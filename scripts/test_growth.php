<?php
declare (strict_types = 1);

/**
 * M3.6 签到 / 积分 / 等级探针
 *
 * 覆盖：签到幂等、连续天数、满 7 天奖励、积分流水与余额、
 *       经验升级、每日上限
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_growth.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use app\service\PointService;
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

function captchaPair(): array
{
    $res = call('api/front/auth/captcha', []);
    $id  = (string) ($res['data']['captcha_id'] ?? '');
    return [$id, (string) Cache::get('captcha:' . $id)];
}

$results = [];
function check($name, $ok, $detail = '') {
    global $results;
    $results[] = [$ok, $name];
    printf("%s %-46s %s\n", $ok ? '[PASS]' : '[FAIL]', $name, $ok ? '' : '← ' . $detail);
}

$stamp  = date('mdHis');
$user   = 'gw_' . $stamp;
$passwd = 'Test@123456';

Db::name('user')->where('username', $user)->delete();

[$capId, $capCode] = captchaPair();
$reg = call('api/front/auth/register', [
    'username' => $user, 'password' => $passwd, 'confirm_password' => $passwd,
    'agree' => 1, 'captcha_id' => $capId, 'captcha_code' => $capCode,
]);
if (($reg['code'] ?? null) !== 0) {
    fwrite(STDERR, '注册失败：' . $reg['raw'] . PHP_EOL);
    exit(1);
}
$token  = (string) $reg['data']['token'];
$userId = (int) $reg['data']['user']['id'];

try {
    // -----------------------------------------------------------
    // 签到
    // -----------------------------------------------------------
    $s1 = call('api/front/signin', [], $token);
    check('首次签到成功', ($s1['code'] ?? null) === 0 && ($s1['data']['signed'] ?? null) === true, $s1['raw']);
    check('首次签到第 1 天', ($s1['data']['days'] ?? null) === 1, $s1['raw']);
    check('签到发放基础积分', ($s1['data']['points'] ?? null) === 5, $s1['raw']);

    $s2 = call('api/front/signin', [], $token);
    check('重复签到被拒（今日已签到）', ($s2['data']['signed'] ?? null) === false, $s2['raw']);

    $status = call('api/front/signin/status', null, $token);
    check('签到状态为已签到', ($status['data']['signed'] ?? null) === true && ($status['data']['days'] ?? null) === 1, $status['raw']);

    $rows = (int) Db::name('user_signin')->where('user_id', $userId)->count();
    check('签到记录只有一条', $rows === 1, "实际 {$rows} 条");

    // 连续签到：造一条昨天的记录（第 6 天），再签到应变成第 7 天并发奖励
    Db::name('user_signin')->where('user_id', $userId)->delete();
    Db::name('user_signin')->insert([
        'user_id'     => $userId,
        'date'        => date('Y-m-d', strtotime('-1 day')),
        'days'        => 6,
        'create_time' => date('Y-m-d H:i:s', strtotime('-1 day')),
    ]);

    $s3 = call('api/front/signin', [], $token);
    check('连续签到累加为第 7 天', ($s3['data']['days'] ?? null) === 7, $s3['raw']);
    check('满 7 天发放额外奖励', ($s3['data']['bonus'] ?? null) === 10 && ($s3['data']['points'] ?? null) === 15, $s3['raw']);

    // -----------------------------------------------------------
    // 积分与经验
    // -----------------------------------------------------------
    $points = call('api/front/points', null, $token);
    check('积分流水可查', ($points['code'] ?? null) === 0 && ($points['data']['total'] ?? 0) >= 2, $points['raw']);
    check('积分余额正确', (int) ($points['data']['balance'] ?? 0) === 20, (string) ($points['data']['balance'] ?? ''));

    $exp = call('api/front/exp', null, $token);
    check('经验流水可查', ($exp['code'] ?? null) === 0 && ($exp['data']['total'] ?? 0) >= 2, $exp['raw']);

    $levels = call('api/front/levels');
    check('等级规则 6 条', ($levels['code'] ?? null) === 0 && count($levels['data']['list'] ?? []) === 6, $levels['raw']);

    // 升级：给 200 经验应升到 2 级（门槛 100），到不了 3 级（门槛 500）
    $newLevel = PointService::addExp($userId, 200, 'test');
    check('经验达标触发升级', $newLevel === 2, "实际等级 {$newLevel}");

    $userRow = Db::name('user')->where('id', $userId)->find();
    $userRow = is_array($userRow) ? $userRow : $userRow->toArray();
    check('等级已写入用户表', (int) $userRow['level'] === 2, (string) $userRow['level']);

    // 每日上限：评论积分上限 10
    check('评论积分未超每日上限', PointService::withinDailyLimit($userId, 'comment', 10) === true);

    // 积分不足时不能扣成负数
    $over = PointService::change($userId, -99999, 'test', '超额扣减');
    check('积分不足时拒绝扣减', $over === false);
} finally {
    Db::name('user_signin')->where('user_id', $userId)->delete();
    Db::name('user_points_log')->where('user_id', $userId)->delete();
    Db::name('user_exp_log')->where('user_id', $userId)->delete();
    Db::name('user')->where('id', $userId)->delete();
    echo str_repeat('-', 78) . "\n临时数据已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
