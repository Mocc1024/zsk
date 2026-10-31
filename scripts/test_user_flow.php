<?php
declare (strict_types = 1);

/**
 * M3.3 用户注册登录与个人中心探针
 *
 * 覆盖：验证码 → 注册 → 登录 → 令牌 → 个人资料 → 双令牌刷新 →
 *       登出失效 → 连续失败锁定 → 注销与冷却期恢复
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_user_flow.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use app\service\Jwt;
use think\facade\Cache;
use think\facade\Db;

const BASE = 'http://127.0.0.1:8000';

function call(string $path, ?array $body = null, string $token = '', string $method = ''): array
{
    $headers = ['Accept: application/json'];
    $payload = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload   = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($method === '') {
        $method = $body !== null ? 'POST' : 'GET';
    }

    $ch = curl_init(BASE . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw  = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true) ?: [];

    return [
        'http' => $http,
        'code' => $json['code'] ?? null,
        'data' => $json['data'] ?? null,
        'msg'  => $json['msg'] ?? substr($raw, 0, 120),
        'raw'  => $raw,
    ];
}

/** 取一组可用的验证码（正确与否存在服务端，测试里直接读出来用） */
function captchaPair(): array
{
    $res = call('api/front/auth/captcha', []);
    $id  = (string) ($res['data']['captcha_id'] ?? '');
    return [$id, (string) Cache::get('captcha:' . $id), $res];
}

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$ok, $name];
    printf("%s %-44s %s\n", $ok ? '[PASS]' : '[FAIL]', $name, $ok ? '' : '← ' . $detail);
}

$stamp = date('mdHis');
$passwd = 'Test@123456';

$uA = 'flow_a_' . $stamp;
$uB = 'flow_b_' . $stamp;
$uC = 'flow_c_' . $stamp;

foreach ([$uA, $uB, $uC] as $name) {
    Db::name('user')->where('username', $name)->delete();
}

$created = [];

try {
    // -----------------------------------------------------------
    // 1. 验证码
    // -----------------------------------------------------------
    [$capId, $capCode, $capRes] = captchaPair();
    check('获取图形验证码', ($capRes['code'] ?? null) === 0 && $capId !== '' && $capCode !== '', $capRes['raw']);
    check('验证码图片是 data URI', str_starts_with((string) ($capRes['data']['image'] ?? ''), 'data:image/png;base64,'));

    // 错误验证码应被拒
    $badReg = call('api/front/auth/register', [
        'username' => $uA, 'password' => $passwd, 'confirm_password' => $passwd,
        'agree' => 1, 'captcha_id' => $capId, 'captcha_code' => 'ZZZZ',
    ]);
    check('错误验证码注册被拒', ($badReg['code'] ?? null) !== 0, $badReg['raw']);

    // -----------------------------------------------------------
    // 2. 注册
    // -----------------------------------------------------------
    [$capId, $capCode] = captchaPair();
    $reg = call('api/front/auth/register', [
        'username' => $uA, 'password' => $passwd, 'confirm_password' => $passwd,
        'agree' => 1, 'captcha_id' => $capId, 'captcha_code' => $capCode,
    ]);
    check('注册成功', ($reg['code'] ?? null) === 0, $reg['raw']);
    check('注册后直接返回令牌', !empty($reg['data']['token']) && !empty($reg['data']['refreshToken']), $reg['raw']);

    $tokenA  = (string) ($reg['data']['token'] ?? '');
    $refresh = (string) ($reg['data']['refreshToken'] ?? '');
    $created[] = (int) ($reg['data']['user']['id'] ?? 0);

    // 弱密码应被拒
    [$capId, $capCode] = captchaPair();
    $weak = call('api/front/auth/register', [
        'username' => 'weak_' . $stamp, 'password' => '123456', 'confirm_password' => '123456',
        'agree' => 1, 'captcha_id' => $capId, 'captcha_code' => $capCode,
    ]);
    check('弱密码注册被拒', ($weak['code'] ?? null) !== 0, $weak['raw']);

    // -----------------------------------------------------------
    // 3. 令牌与个人资料
    // -----------------------------------------------------------
    check('未登录访问受限接口 401', (call('api/front/ping')['code'] ?? null) === 401);
    check('已登录访问受限接口 200', (call('api/front/ping', null, $tokenA)['code'] ?? null) === 0);

    $upd = call('api/front/user/profile', ['nickname' => '新昵称', 'bio' => '探针简介'], $tokenA, 'PUT');
    check('修改个人资料', ($upd['code'] ?? null) === 0, $upd['raw']);

    $prof = call('api/front/user/profile', null, $tokenA);
    check('资料已生效', ($prof['data']['user']['nickname'] ?? '') === '新昵称', $prof['raw']);
    check('资料不含密码字段', !array_key_exists('password', (array) ($prof['data']['user'] ?? [])), $prof['raw']);

    // -----------------------------------------------------------
    // 4. 双令牌刷新
    // -----------------------------------------------------------
    $refreshed = call('api/front/auth/refresh', ['refresh_token' => $refresh]);
    check('Refresh Token 换新 Access', ($refreshed['code'] ?? null) === 0 && !empty($refreshed['data']['token']), $refreshed['raw']);
    $newToken = (string) ($refreshed['data']['token'] ?? '');
    check('新 Access 可用', (call('api/front/ping', null, $newToken)['code'] ?? null) === 0);
    check('Refresh 不能当 Access 用', (call('api/front/ping', null, $refresh)['code'] ?? null) === 401);

    // -----------------------------------------------------------
    // 5. 登出
    // -----------------------------------------------------------
    $out = call('api/front/auth/logout', ['refresh_token' => $refresh], $newToken);
    check('登出成功', ($out['code'] ?? null) === 0, $out['raw']);
    check('登出后 Access 失效', (call('api/front/ping', null, $newToken)['code'] ?? null) === 401);
    check('登出后 Refresh 失效', (call('api/front/auth/refresh', ['refresh_token' => $refresh])['code'] ?? null) === 401);

    // -----------------------------------------------------------
    // 6. 连续失败锁定（换一个账号，避免污染上面的用例）
    // -----------------------------------------------------------
    [$capId, $capCode] = captchaPair();
    $regB = call('api/front/auth/register', [
        'username' => $uB, 'password' => $passwd, 'confirm_password' => $passwd,
        'agree' => 1, 'captcha_id' => $capId, 'captcha_code' => $capCode,
    ]);
    $created[] = (int) ($regB['data']['user']['id'] ?? 0);

    $lockedAt = 0;
    for ($i = 1; $i <= 5; $i++) {
        [$capId, $capCode] = captchaPair();
        $res = call('api/front/auth/login', [
            'username' => $uB, 'password' => 'WrongPass123',
            'captcha_id' => $capId, 'captcha_code' => $capCode,
        ]);
        if (($res['code'] ?? null) === 1003) {
            $lockedAt = $i;
            break;
        }
    }
    check('连续失败 5 次触发锁定', $lockedAt === 5, "第 {$lockedAt} 次触发");

    // 锁定期内即使密码正确也必须拒绝
    [$capId, $capCode] = captchaPair();
    $rightPass = call('api/front/auth/login', [
        'username' => $uB, 'password' => $passwd,
        'captcha_id' => $capId, 'captcha_code' => $capCode,
    ]);
    check('锁定期内正确密码也被拒', ($rightPass['code'] ?? null) === 1003, $rightPass['raw']);

    // -----------------------------------------------------------
    // 7. 注销与冷却期恢复
    // -----------------------------------------------------------
    [$capId, $capCode] = captchaPair();
    $regC = call('api/front/auth/register', [
        'username' => $uC, 'password' => $passwd, 'confirm_password' => $passwd,
        'agree' => 1, 'captcha_id' => $capId, 'captcha_code' => $capCode,
    ]);
    check('注册 C 成功', ($regC['code'] ?? null) === 0, $regC['raw']);
    $tokenC = (string) ($regC['data']['token'] ?? '');
    $created[] = (int) ($regC['data']['user']['id'] ?? 0);

    $del = call('api/front/user/deactivate', [], $tokenC);
    check('注销账号', ($del['code'] ?? null) === 0, $del['raw']);
    check('注销后令牌失效', (call('api/front/ping', null, $tokenC)['code'] ?? null) === 401);

    [$capId, $capCode] = captchaPair();
    $back = call('api/front/auth/login', [
        'username' => $uC, 'password' => $passwd,
        'captcha_id' => $capId, 'captcha_code' => $capCode,
    ]);
    check('冷却期内重新登录可恢复', ($back['code'] ?? null) === 0, $back['raw']);

    $row = Db::name('user')->where('username', $uC)->find();
    $row = $row ? (is_array($row) ? $row : $row->toArray()) : null;
    check('恢复后 delete_time 已清空', $row !== null && empty($row['delete_time']), json_encode($row));
} finally {
    foreach ($created as $id) {
        if ($id > 0) {
            Db::name('user')->where('id', $id)->delete();
        }
    }
    echo str_repeat('-', 78) . "\n临时用户已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
