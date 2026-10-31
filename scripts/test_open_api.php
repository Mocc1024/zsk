<?php
declare (strict_types = 1);

/**
 * M3.1 开放内容 API 探针
 *
 * 覆盖验收项：
 *   · 合法签名正常取数（分类 / 标签 / 列表 / 详情）
 *   · 错签名被拒
 *   · 下架后开放 API 立即不可见
 *   · 轮换密钥后旧签名失效
 *   · QPS 超限返回 429
 *   · 后台列表的 secret 是脱敏的
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_open_api.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use app\middleware\OpenAuth;
use think\facade\Db;

const BASE = 'http://127.0.0.1:8000';

/** 发一次 HTTP 请求；$query 会作为查询串参与签名 */
function call(string $path, array $query = [], string $token = '', string $method = 'GET', ?array $body = null): array
{
    $url = BASE . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $payload = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload   = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
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
        'msg'  => $json['msg'] ?? substr($raw, 0, 100),
        'raw'  => $raw,
    ];
}

/** 生成一次合法签名的查询参数 */
function signed(string $appKey, string $secret, array $extra = []): array
{
    $params = array_merge([
        'appkey'    => $appKey,
        'timestamp' => (string) time(),
        'nonce'     => bin2hex(random_bytes(8)),
    ], $extra);
    $params['sign'] = OpenAuth::sign($params, $secret);

    return $params;
}

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$ok, $name];
    printf("%s %-44s %s\n", $ok ? '[PASS]' : '[FAIL]', $name, $ok ? '' : '← ' . $detail);
}

// ---------------------------------------------------------------
// 准备：临时超管 + 临时已发布内容
// ---------------------------------------------------------------
$suffix  = date('mdHis');
$admUser = 'probe_open_admin_' . $suffix;
$passwd  = 'Probe@123456';

Db::name('admin')->whereLike('username', 'probe_open_admin_%')->delete();

$adminId = Db::name('admin')->insertGetId([
    'username'    => $admUser,
    'password'    => password_hash($passwd, PASSWORD_BCRYPT),
    'nickname'    => '开放API探针管理员',
    'group_id'    => 1, // 超级管理员组
    'status'      => 1,
    'create_time' => date('Y-m-d H:i:s'),
    'update_time' => date('Y-m-d H:i:s'),
]);

$login = call('api/auth/login', [], '', 'POST', ['username' => $admUser, 'password' => $passwd]);
if (($login['code'] ?? null) !== 0) {
    fwrite(STDERR, '登录失败：' . $login['raw'] . PHP_EOL);
    exit(1);
}
$adminToken = (string) $login['data']['token'];

// 临时内容：确保库里有确定的已发布内容可查
$contentId = (int) Db::name('content')->insertGetId([
    'category_id'  => 1,
    'title'        => '探针内容_' . $suffix,
    'author'       => '探针',
    'summary'      => '用于验证开放 API 的可见性',
    'content'      => '<p>正文<script>alert(1)</script></p>',
    'status'       => 1,
    'is_top'       => 0,
    'views'        => 0,
    'publish_time' => date('Y-m-d H:i:s'),
    'create_time'  => date('Y-m-d H:i:s'),
    'update_time'  => date('Y-m-d H:i:s'),
]);

try {
    // -----------------------------------------------------------
    // 1. 后台创建应用
    // -----------------------------------------------------------
    $created = call('api/open-app', [], $adminToken, 'POST', [
        'name' => '探针应用_' . $suffix,
        'qps'  => 50,
    ]);
    check('后台创建应用', ($created['code'] ?? null) === 0, $created['raw']);

    $appKey    = (string) ($created['data']['app_key'] ?? '');
    $appSecret = (string) ($created['data']['app_secret'] ?? '');
    $appId     = (int) ($created['data']['id'] ?? 0);
    check('返回明文 app_secret', strlen($appSecret) >= 32, '实际长度 ' . strlen($appSecret));

    $list = call('api/open-app', [], $adminToken);
    $masked = '';
    foreach ((array) ($list['data']['list'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $appId) {
            $masked = (string) $item['app_secret'];
        }
    }
    check('后台列表 secret 已脱敏', str_contains($masked, '*') && !str_contains($masked, $appSecret), $masked);

    // -----------------------------------------------------------
    // 2. 开放接口取数
    // -----------------------------------------------------------
    $cats = call('api/open/categories', signed($appKey, $appSecret));
    check('开放API 分类列表', ($cats['code'] ?? null) === 0 && is_array($cats['data']['list'] ?? null), $cats['raw']);

    $tags = call('api/open/tags', signed($appKey, $appSecret));
    check('开放API 标签列表', ($tags['code'] ?? null) === 0, $tags['raw']);

    $list2 = call('api/open/contents', signed($appKey, $appSecret, ['limit' => 5]));
    check('开放API 内容列表', ($list2['code'] ?? null) === 0, $list2['raw']);

    $detail = call('api/open/content/' . $contentId, signed($appKey, $appSecret));
    check('开放API 内容详情', ($detail['code'] ?? null) === 0, $detail['raw']);
    $body = (string) ($detail['data']['content']['content'] ?? '');
    check('正文已过滤 script', !str_contains($body, '<script'), $body);

    // -----------------------------------------------------------
    // 3. 下架后立即不可见
    // -----------------------------------------------------------
    Db::name('content')->where('id', $contentId)->update(['status' => 2]);
    $offline = call('api/open/content/' . $contentId, signed($appKey, $appSecret));
    check('下架后详情不可见（404）', ($offline['code'] ?? null) === 404, $offline['raw']);

    $stillInList = call('api/open/contents', signed($appKey, $appSecret, ['keyword' => '探针内容_' . $suffix]));
    $found = false;
    foreach ((array) ($stillInList['data']['list'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $contentId) {
            $found = true;
        }
    }
    check('下架后不出现在列表', !$found, '仍在列表中');

    Db::name('content')->where('id', $contentId)->update(['status' => 1]);
    $back = call('api/open/content/' . $contentId, signed($appKey, $appSecret));
    check('重新上架后恢复可见', ($back['code'] ?? null) === 0, $back['raw']);

    // -----------------------------------------------------------
    // 4. 错签名 / 轮换密钥
    // -----------------------------------------------------------
    $bad       = signed($appKey, $appSecret);
    $bad['sign'] = str_repeat('a', 64);
    check('错签名被拒', (call('api/open/contents', $bad)['code'] ?? null) === 401);

    $rotated = call('api/open-app/' . $appId . '/rotate', [], $adminToken, 'POST');
    check('轮换密钥', ($rotated['code'] ?? null) === 0, $rotated['raw']);
    $newSecret = (string) ($rotated['data']['app_secret'] ?? '');

    check('旧密钥签名失效', (call('api/open/contents', signed($appKey, $appSecret))['code'] ?? null) === 401);
    check('新密钥签名可用', (call('api/open/contents', signed($appKey, $newSecret))['code'] ?? null) === 0);

    // -----------------------------------------------------------
    // 5. 限流 429
    // -----------------------------------------------------------
    call('api/open-app/' . $appId, [], $adminToken, 'PUT', ['qps' => 1]);
    $got429 = false;
    for ($i = 0; $i < 5; $i++) {
        $res = call('api/open/contents', signed($appKey, $newSecret));
        if (($res['code'] ?? null) === 429) {
            $got429 = true;
            break;
        }
    }
    check('QPS 超限返回 429', $got429, '未触发限流');
} finally {
    // -----------------------------------------------------------
    // 清理
    // -----------------------------------------------------------
    Db::name('content')->where('id', $contentId)->delete();
    Db::name('open_app')->whereLike('app_key', 'ak_%')->where('name', 'like', '探针应用_%')->delete();
    Db::name('admin')->where('id', $adminId)->delete();
    echo str_repeat('-', 78) . "\n临时数据已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
