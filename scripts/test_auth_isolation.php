<?php
declare (strict_types = 1);

/**
 * 三套鉴权体系交叉探针（M3.0）
 *
 * 为什么要有这个脚本：后台 JWT、C 端用户 Token、开放 API 签名三套体系
 * 必须互不串用。这条约束靠「读代码正确」保证不了，只能靠真实请求验证。
 * 参照第二阶段 11.7 / 11.12.2 的做法：临时建号 → 探测 → 立即删除。
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_auth_isolation.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use app\middleware\OpenAuth;
use app\service\Jwt;
use app\service\TokenBlacklist;
use think\facade\Db;

const BASE = 'http://127.0.0.1:8000';

$suffix   = date('mdHis');
$admUser  = 'probe_admin_' . $suffix;
$appUser  = 'probe_user_' . $suffix;
$password = 'Probe@123456';

/** 发一次真实 HTTP 请求 */
function call(string $path, array $query = [], string $token = ''): array
{
    $url = BASE . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw  = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = json_decode($raw, true);

    return [
        'http' => $http,
        'code' => $json['code'] ?? null,
        'msg'  => $json['msg'] ?? ($err !== '' ? 'CURL 错误：' . $err : substr($raw, 0, 80)),
    ];
}

$results = [];
function probe(string $name, array $res, int $expect): void
{
    global $results;
    $actual = $res['code'] ?? $res['http'];
    $ok     = $actual === $expect;
    $results[] = [$ok, $name, $expect, $actual, $res['msg']];
    printf(
        "%s %-42s 期望 %-4d 实际 %-4s %s\n",
        $ok ? '[PASS]' : '[FAIL]',
        $name,
        $expect,
        (string) $actual,
        $ok ? '' : '← ' . $res['msg']
    );
}

// ---------------------------------------------------------------
// 0. 准备临时数据（跑完立即删除）
// ---------------------------------------------------------------
Db::name('admin')->whereLike('username', 'probe_admin_%')->delete();
Db::name('user')->whereLike('username', 'probe_user_%')->delete();
Db::name('open_app')->whereLike('app_key', 'probe_app_%')->delete();

$adminId = Db::name('admin')->insertGetId([
    'username'        => $admUser,
    'password'        => password_hash($password, PASSWORD_BCRYPT),
    'nickname'        => '探针管理员',
    'group_id'        => 2,
    'status'          => 1,
    'create_time'     => date('Y-m-d H:i:s'),
    'update_time'     => date('Y-m-d H:i:s'),
]);

$userId = Db::name('user')->insertGetId([
    'username'    => $appUser,
    'password'    => password_hash($password, PASSWORD_BCRYPT),
    'nickname'    => '探针用户',
    'status'      => 1,
    'level'       => 1,
    'create_time' => date('Y-m-d H:i:s'),
    'update_time' => date('Y-m-d H:i:s'),
]);

$appKey    = 'probe_app_' . $suffix;
$appSecret = bin2hex(random_bytes(16));
Db::name('open_app')->insert([
    'name'        => '探针应用',
    'app_key'     => $appKey,
    'app_secret'  => $appSecret,
    'allow_paths' => '*',
    'qps'         => 50,
    'status'      => 1,
    'create_time' => date('Y-m-d H:i:s'),
    'update_time' => date('Y-m-d H:i:s'),
]);

echo "临时数据：admin#{$adminId} user#{$userId} app={$appKey}\n";
echo str_repeat('-', 78) . "\n";

// ---------------------------------------------------------------
// 1. 后台管理员令牌（走真实登录，验证 AuthController 会带上 role）
// ---------------------------------------------------------------
$login = null;
$ch    = curl_init(BASE . '/api/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['username' => $admUser, 'password' => $password], JSON_UNESCAPED_UNICODE),
]);
$raw = (string) curl_exec($ch);
curl_close($ch);
$login = json_decode($raw, true);

if (($login['code'] ?? null) !== 0) {
    fwrite(STDERR, "登录失败，无法继续：" . $raw . PHP_EOL);
    exit(1);
}
$adminToken = (string) $login['data']['token'];
$payload    = Jwt::decode($adminToken);
echo '后台令牌 role=' . Jwt::roleOf($payload) . ' type=' . Jwt::typeOf($payload) . "\n";

// ---------------------------------------------------------------
// 2. C 端用户令牌（M3.3 才做注册登录，这里直接签发用于验证中间件）
// ---------------------------------------------------------------
$userToken        = Jwt::encode(['id' => $userId, 'username' => $appUser, 'role' => Jwt::ROLE_USER, 'type' => Jwt::TYPE_ACCESS]);
$userRefreshToken = Jwt::encode(['id' => $userId, 'username' => $appUser, 'role' => Jwt::ROLE_USER, 'type' => Jwt::TYPE_REFRESH]);

// ---------------------------------------------------------------
// 3. 探针
// ---------------------------------------------------------------
probe('后台令牌 访问 后台接口', call('api/auth/profile', [], $adminToken), 0);
probe('后台令牌 访问 C端接口（应拒）', call('api/front/ping', [], $adminToken), 401);
probe('C端令牌 访问 C端接口', call('api/front/ping', [], $userToken), 0);
probe('C端令牌 访问 后台接口（应拒）', call('api/auth/profile', [], $userToken), 401);
probe('无令牌 访问 C端接口', call('api/front/ping'), 401);
probe('Refresh Token 访问 C端接口（应拒）', call('api/front/ping', [], $userRefreshToken), 401);

// 开放 API
$ts   = (string) time();
$nonce = bin2hex(random_bytes(8));
$base  = ['appkey' => $appKey, 'timestamp' => $ts, 'nonce' => $nonce];

probe('开放API 缺鉴权参数', call('api/open/ping'), 401);

$bad          = $base;
$bad['sign']  = str_repeat('0', 64);
probe('开放API 签名错误', call('api/open/ping', $bad), 401);

$old              = $base;
$old['timestamp'] = (string) (time() - 3600);
$old['sign']      = OpenAuth::sign($old, $appSecret);
probe('开放API timestamp 超窗', call('api/open/ping', $old), 401);

$good         = $base;
$good['sign'] = OpenAuth::sign($good, $appSecret);
probe('开放API 签名正确', call('api/open/ping', $good), 0);

probe('开放API 重放同一 nonce', call('api/open/ping', $good), 401);

// 黑名单
TokenBlacklist::add($userToken);
probe('C端令牌 加入黑名单后（应拒）', call('api/front/ping', [], $userToken), 401);

// ---------------------------------------------------------------
// 4. 清理
// ---------------------------------------------------------------
Db::name('admin')->where('id', $adminId)->delete();
Db::name('user')->where('id', $userId)->delete();
Db::name('open_app')->where('app_key', $appKey)->delete();
echo str_repeat('-', 78) . "\n";
echo "临时数据已删除\n";

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
