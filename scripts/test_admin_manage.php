<?php
declare (strict_types = 1);

/**
 * M3.7 后台管理探针（用户管理 / 评论审核 / 举报 / 等级 / 积分规则）
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_admin_manage.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use app\service\Setting;
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

    return ['http' => $http, 'code' => $json['code'] ?? null, 'data' => $json['data'] ?? null, 'msg' => $json['msg'] ?? '', 'raw' => $raw];
}

$results = [];
function check($name, $ok, $detail = '') {
    global $results;
    $results[] = [$ok, $name];
    printf("%s %-46s %s\n", $ok ? '[PASS]' : '[FAIL]', $name, $ok ? '' : '← ' . $detail);
}

$stamp   = date('mdHis');
$admUser = 'probe_mgr_' . $stamp;
$passwd  = 'Probe@123456';
$userName = 'mgr_user_' . $stamp;

Db::name('admin')->whereLike('username', 'probe_mgr_%')->delete();
Db::name('user')->whereLike('username', 'mgr_user_%')->delete();
Db::name('user')->whereLike('username', 'mgr_new%')->delete();
Db::name('user')->whereLike('username', 'mgr_weak%')->delete();

$adminId = Db::name('admin')->insertGetId([
    'username'    => $admUser,
    'password'    => password_hash($passwd, PASSWORD_BCRYPT),
    'nickname'    => '后台管理探针',
    'group_id'    => 1,
    'status'      => 1,
    'create_time' => date('Y-m-d H:i:s'),
    'update_time' => date('Y-m-d H:i:s'),
]);

$login = call('api/auth/login', ['username' => $admUser, 'password' => $passwd]);
if (($login['code'] ?? null) !== 0) {
    fwrite(STDERR, '登录失败：' . $login['raw'] . PHP_EOL);
    exit(1);
}
$token = (string) $login['data']['token'];

// 临时 C 端用户与内容、评论、举报
$userId = (int) Db::name('user')->insertGetId([
    'username'    => $userName,
    'password'    => password_hash('User@123456', PASSWORD_BCRYPT),
    'nickname'    => '探针用户',
    'status'      => 1,
    'level'       => 1,
    'create_time' => date('Y-m-d H:i:s'),
    'update_time' => date('Y-m-d H:i:s'),
]);

$contentId = (int) Db::name('content')->insertGetId([
    'category_id' => 1, 'title' => '后台管理探针内容_' . $stamp, 'author' => '探针',
    'summary' => '探针', 'content' => '<p>正文</p>', 'status' => 1,
    'publish_time' => date('Y-m-d H:i:s'), 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
]);

$commentId = (int) Db::name('comment')->insertGetId([
    'user_id' => $userId, 'content_id' => $contentId, 'pid' => 0, 'root_id' => 0,
    'content' => '待审核评论', 'status' => 0, 'ip' => '127.0.0.1',
    'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
]);

$reportId = (int) Db::name('user_report')->insertGetId([
    'user_id' => $userId, 'target_type' => 'comment', 'target_id' => $commentId,
    'reason' => '探针举报', 'status' => 0,
    'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
]);

try {
    // -----------------------------------------------------------
    // 用户管理
    // -----------------------------------------------------------
    $list = call('api/user-manage', null, $token);
    check('用户列表', ($list['code'] ?? null) === 0 && is_array($list['data']['list'] ?? null), $list['raw']);

    // ---- 后台新建用户 ----
    $newName = 'mgr_new_' . $stamp;
    $create1 = call('api/user-manage', ['username' => $newName, 'nickname' => '后台新建'], $token);
    check('后台新建用户', ($create1['code'] ?? null) === 0 && (int) ($create1['data']['id'] ?? 0) > 0, $create1['raw']);

    $initPass = (string) ($create1['data']['password'] ?? '');
    check('未填密码时回传初始密码', strlen($initPass) >= 8, $initPass);

    // 第二个不填手机号的用户也必须能建成：
    // mobile 唯一索引允许多个 NULL，但不允许两个空串 —— 留空要写 NULL
    $create2 = call('api/user-manage', ['username' => 'mgr_new2_' . $stamp], $token);
    check('第二个未填手机号的用户也能创建', ($create2['code'] ?? null) === 0, $create2['raw']);
    $mobile2 = Db::name('user')->where('id', (int) ($create2['data']['id'] ?? 0))->value('mobile');
    check('未填手机号存的是 NULL', $mobile2 === null, var_export($mobile2, true));

    $dup = call('api/user-manage', ['username' => $newName], $token);
    check('重名用户被拒绝', ($dup['code'] ?? null) !== 0, $dup['raw']);

    $create3 = call('api/user-manage', ['username' => 'mgr_new3_' . $stamp, 'password' => 'Manual@123'], $token);
    check('指定密码新建用户', ($create3['code'] ?? null) === 0, $create3['raw']);
    check('指定密码时不回传密码', (string) ($create3['data']['password'] ?? '') === '', $create3['raw']);

    $weak = call('api/user-manage', ['username' => 'mgr_weak_' . $stamp, 'password' => '123'], $token);
    check('弱密码被拒绝', ($weak['code'] ?? null) !== 0, $weak['raw']);

    // 初始密码真的能用来登录
    $cap   = call('api/front/auth/captcha', []);
    $capId = (string) ($cap['data']['captcha_id'] ?? '');
    $lg    = call('api/front/auth/login', [
        'username'     => $newName,
        'password'     => $initPass,
        'captcha_id'   => $capId,
        'captcha_code' => (string) Cache::get('captcha:' . $capId),
    ]);
    check('新建用户可用初始密码登录', ($lg['code'] ?? null) === 0, $lg['raw']);

    $read = call("api/user-manage/{$userId}", null, $token);
    check('用户详情', ($read['code'] ?? null) === 0, $read['raw']);
    check('详情不含密码', !array_key_exists('password', (array) ($read['data']['user'] ?? [])));

    $upd = call("api/user-manage/{$userId}", ['nickname' => '改名后的用户'], $token, 'PUT');
    check('编辑用户资料', ($upd['code'] ?? null) === 0, $upd['raw']);

    $off = call("api/user-manage/{$userId}/status", ['status' => 0], $token, 'PUT');
    check('禁用用户', ($off['code'] ?? null) === 0, $off['raw']);

    $on = call("api/user-manage/{$userId}/status", ['status' => 1], $token, 'PUT');
    check('启用用户', ($on['code'] ?? null) === 0, $on['raw']);

    $reset = call("api/user-manage/{$userId}/reset-password", [], $token);
    check('重置密码返回新密码', ($reset['code'] ?? null) === 0 && strlen((string) ($reset['data']['password'] ?? '')) >= 8, $reset['raw']);

    // 用新密码走前台登录，确认重置真的生效
    $newPass = (string) $reset['data']['password'];
    $cap = call('api/front/auth/captcha', []);
    $capId = (string) ($cap['data']['captcha_id'] ?? '');
    $frontLogin = call('api/front/auth/login', [
        'username' => $userName, 'password' => $newPass,
        'captcha_id' => $capId, 'captcha_code' => (string) Cache::get('captcha:' . $capId),
    ]);
    check('新密码可以登录', ($frontLogin['code'] ?? null) === 0, $frontLogin['raw']);

    $balBefore = (int) Db::name('user')->where('id', $userId)->value('points');
    $adj = call("api/user-manage/{$userId}/points", ['points' => 50, 'remark' => '探针调整'], $token);
    $balAfter = (int) Db::name('user')->where('id', $userId)->value('points');
    check('调整积分余额变化', ($adj['code'] ?? null) === 0 && $balAfter === $balBefore + 50, "before={$balBefore} after={$balAfter}");

    $logRow = Db::name('user_points_log')->where('user_id', $userId)->where('scene', 'admin_adjust')->find();
    check('调整积分有流水记录', !empty($logRow), '未找到 admin_adjust 流水');

    $expAdj = call("api/user-manage/{$userId}/exp", ['exp' => 200], $token);
    check('调整经验触发升级', ($expAdj['code'] ?? null) === 0 && (int) ($expAdj['data']['level'] ?? 0) === 2, $expAdj['raw']);

    // -----------------------------------------------------------
    // 等级规则
    // -----------------------------------------------------------
    $levels = call('api/level', null, $token);
    check('等级列表 6 条', ($levels['code'] ?? null) === 0 && count($levels['data']['list'] ?? []) === 6, $levels['raw']);

    $newLevel = call('api/level', ['level' => 9, 'name' => '探针等级', 'min_exp' => 99999], $token);
    check('新增等级', ($newLevel['code'] ?? null) === 0, $newLevel['raw']);

    $levelRow = Db::name('user_level')->where('level', 9)->find();
    $levelRow = is_array($levelRow) ? $levelRow : $levelRow->toArray();
    call('api/level/' . $levelRow['id'], ['name' => '探针等级改名'], $token, 'PUT');
    $renamed = (string) Db::name('user_level')->where('level', 9)->value('name');
    check('编辑等级', $renamed === '探针等级改名', $renamed);

    // -----------------------------------------------------------
    // 积分规则
    // -----------------------------------------------------------
    $rules = call('api/points/rule', null, $token);
    check('积分规则可读取', ($rules['code'] ?? null) === 0 && is_array($rules['data']['list'] ?? null), $rules['raw']);

    call('api/points/rule', ['signin_base' => 7], $token);
    check('积分规则修改即时生效', Setting::int('points.signin_base') === 7, (string) Setting::int('points.signin_base'));
    call('api/points/rule', ['signin_base' => 5], $token);

    $stat = call('api/points/stat', null, $token);
    check('积分统计', ($stat['code'] ?? null) === 0 && isset($stat['data']['gain']), $stat['raw']);

    // -----------------------------------------------------------
    // 评论审核
    // -----------------------------------------------------------
    $auditList = call('api/comment-audit?status=0', null, $token);
    check('待审评论列表', ($auditList['code'] ?? null) === 0, $auditList['raw']);

    $ccBefore = (int) Db::name('content')->where('id', $contentId)->value('comment_count');
    $pass = call("api/comment-audit/{$commentId}/audit", ['result' => 1], $token, 'PUT');
    $ccAfter = (int) Db::name('content')->where('id', $contentId)->value('comment_count');
    check('审核通过后冗余计数 +1', ($pass['code'] ?? null) === 0 && $ccAfter === $ccBefore + 1, "before={$ccBefore} after={$ccAfter}");

    $rej = call("api/comment-audit/{$commentId}/audit", ['result' => 2], $token, 'PUT');
    $ccRej = (int) Db::name('content')->where('id', $contentId)->value('comment_count');
    check('驳回后冗余计数 -1', ($rej['code'] ?? null) === 0 && $ccRej === $ccBefore, "计数 {$ccRej}");

    $cfg = call('api/comment-audit/config', null, $token);
    check('评论配置可读取', ($cfg['code'] ?? null) === 0, $cfg['raw']);

    call('api/comment-audit/config', ['audit_first' => 1], $token, 'PUT');
    check('评论配置修改生效', Setting::int('comment.audit_first') === 1, (string) Setting::int('comment.audit_first'));
    call('api/comment-audit/config', ['audit_first' => 0], $token, 'PUT');

    // -----------------------------------------------------------
    // 敏感词
    // -----------------------------------------------------------
    $sw = call('api/sensitive-word', null, $token);
    check('敏感词列表', ($sw['code'] ?? null) === 0, $sw['raw']);

    $add = call('api/sensitive-word', ['word' => '探针敏感词', 'level' => 3], $token);
    check('新增敏感词', ($add['code'] ?? null) === 0, $add['raw']);

    // 缓存必须已失效，否则新词要等一小时才生效
    $words = \app\service\SensitiveWordService::words();
    $found = false;
    foreach ($words as $w) {
        if ($w['word'] === '探针敏感词') {
            $found = true;
        }
    }
    check('新增敏感词即时进入词库（缓存已失效）', $found, '词库里还没有新词');

    $swRow = Db::name('sensitive_word')->where('word', '探针敏感词')->find();
    $swRow = is_array($swRow) ? $swRow : $swRow->toArray();
    call('api/sensitive-word/' . $swRow['id'], null, $token, 'DELETE');
    check('删除敏感词', (int) Db::name('sensitive_word')->where('word', '探针敏感词')->count() === 0);

    // -----------------------------------------------------------
    // 举报处理
    // -----------------------------------------------------------
    $rpList = call('api/report', null, $token);
    check('举报列表', ($rpList['code'] ?? null) === 0, $rpList['raw']);

    $handle = call("api/report/{$reportId}/handle", ['result' => 1, 'handle_result' => '已删除该评论'], $token, 'PUT');
    $rpRow = Db::name('user_report')->where('id', $reportId)->find();
    $rpRow = is_array($rpRow) ? $rpRow : $rpRow->toArray();
    check('处理举报', ($handle['code'] ?? null) === 0 && (int) $rpRow['status'] === 1, $handle['raw']);
    check('举报处理记录了处理人', (int) ($rpRow['admin_id'] ?? 0) > 0, (string) ($rpRow['admin_id'] ?? ''));
} finally {
    Db::name('user_report')->where('id', $reportId)->delete();
    Db::name('comment')->where('id', $commentId)->delete();
    Db::name('content')->where('id', $contentId)->delete();
    Db::name('user_level')->where('level', 9)->delete();
    Db::name('user_points_log')->where('user_id', $userId)->delete();
    Db::name('user_exp_log')->where('user_id', $userId)->delete();
    Db::name('user')->where('id', $userId)->delete();
    Db::name('user')->whereLike('username', 'mgr_new%')->delete();
    Db::name('user')->whereLike('username', 'mgr_weak%')->delete();
    Db::name('admin')->where('id', $adminId)->delete();
    echo str_repeat('-', 78) . "\n临时数据已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
