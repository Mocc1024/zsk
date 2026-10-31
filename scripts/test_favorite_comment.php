<?php
declare (strict_types = 1);

/**
 * M3.4 收藏 + M3.5 评论探针
 *
 * 覆盖：
 *   收藏幂等 / 取消物理删除 / 冗余计数同步 / 未发布内容不可收藏
 *   评论两级回复 / 敏感词拦截与替换 / 先审后发 / 点赞幂等 / 举报去重
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_favorite_comment.php
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

    return ['http' => $http, 'code' => $json['code'] ?? null, 'data' => $json['data'] ?? null, 'raw' => $raw];
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

$stamp = date('mdHis');
$user  = 'fc_' . $stamp;
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
$token   = (string) $reg['data']['token'];
$userId  = (int) $reg['data']['user']['id'];

$contentId = (int) Db::name('content')->insertGetId([
    'category_id' => 1, 'title' => '收藏评论探针内容_' . $stamp, 'author' => '探针',
    'summary' => '探针', 'content' => '<p>正文</p>', 'status' => 1, 'views' => 0,
    'publish_time' => date('Y-m-d H:i:s'), 'create_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s'),
]);

try {
    // =========================================================
    // 等级只是成长标记，不是权限门槛
    // =========================================================
    // 刻意断言当前用户就是最低等级：下面的收藏 / 评论 / 点赞必须全部可用。
    // 哪天有人加回「Lv N 才能点赞」这类判断，这条会先炸，提醒等级不参与鉴权。
    $lv = (int) Db::name('user')->where('id', $userId)->value('level');
    check('当前用户是 Lv1（等级不是操作门槛）', $lv === 1, "实际 Lv{$lv}");

    // 把经验清零后再确认一次：等级低也照样能互动
    Db::name('user')->where('id', $userId)->update(['exp' => 0, 'level' => 1]);

    // =========================================================
    // M3.4 收藏
    // =========================================================
    $fav = call('api/front/favorite', ['content_id' => $contentId], $token);
    check('收藏成功', ($fav['code'] ?? null) === 0, $fav['raw']);

    $count1 = (int) Db::name('content')->where('id', $contentId)->value('favorite_count');
    check('收藏数 +1', $count1 === 1, "实际 {$count1}");

    $fav2 = call('api/front/favorite', ['content_id' => $contentId], $token);
    $count2 = (int) Db::name('content')->where('id', $contentId)->value('favorite_count');
    check('重复收藏幂等（计数不涨）', ($fav2['code'] ?? null) === 0 && $count2 === 1, "计数 {$count2}");

    $chk = call('api/front/favorite/check', null, $token) ;
    $chk = call("api/front/favorite/check?content_id={$contentId}", null, $token);
    check('已收藏状态查询', ($chk['data']['favorited'] ?? null) === true, $chk['raw']);

    $list = call('api/front/favorites', null, $token);
    $inList = false;
    foreach ((array) ($list['data']['list'] ?? []) as $item) {
        if ((int) ($item['content_id'] ?? 0) === $contentId) {
            $inList = true;
        }
    }
    check('收藏列表包含该内容', ($list['code'] ?? null) === 0 && $inList, $list['raw']);

    $del = call("api/front/favorite/{$contentId}", null, $token, 'DELETE');
    $count3 = (int) Db::name('content')->where('id', $contentId)->value('favorite_count');
    check('取消收藏计数 -1', ($del['code'] ?? null) === 0 && $count3 === 0, "计数 {$count3}");

    $rows = (int) Db::name('user_favorite')->where('user_id', $userId)->where('content_id', $contentId)->count();
    check('取消后记录已被物理删除', $rows === 0, "残留 {$rows} 行");

    $del2 = call("api/front/favorite/{$contentId}", null, $token, 'DELETE');
    $count4 = (int) Db::name('content')->where('id', $contentId)->value('favorite_count');
    check('重复取消不产生负数', $count4 === 0, "计数 {$count4}");

    $badFav = call('api/front/favorite', ['content_id' => 99999999], $token);
    check('收藏不存在内容返回 404', ($badFav['code'] ?? null) === 404, $badFav['raw']);

    // =========================================================
    // M3.5 评论
    // =========================================================
    $c1 = call('api/front/comment', ['content_id' => $contentId, 'content' => '这是一条正常评论'], $token);
    check('发表评论成功', ($c1['code'] ?? null) === 0, $c1['raw']);
    $topId = (int) ($c1['data']['id'] ?? 0);
    check('默认直接通过（未开先审）', ($c1['data']['status'] ?? null) === 1, $c1['raw']);

    $cc1 = (int) Db::name('content')->where('id', $contentId)->value('comment_count');
    check('评论数 +1', $cc1 === 1, "实际 {$cc1}");

    $reply = call('api/front/comment', ['content_id' => $contentId, 'content' => '这是回复', 'pid' => $topId], $token);
    check('回复成功', ($reply['code'] ?? null) === 0, $reply['raw']);

    $list2 = call("api/front/comments?content_id={$contentId}", null, $token);
    $hasReply = false;
    foreach ((array) ($list2['data']['list'] ?? []) as $top) {
        if ((int) ($top['id'] ?? 0) === $topId && count($top['replies'] ?? []) === 1) {
            $hasReply = true;
        }
    }
    check('楼中楼结构（回复挂在楼下）', $hasReply, $list2['raw']);

    // 敏感词：3 级拦截转待审
    $block = call('api/front/comment', ['content_id' => $contentId, 'content' => '专业刷单团队'], $token);
    check('敏感词命中转待审', ($block['code'] ?? null) === 0 && ($block['data']['status'] ?? null) === 0, $block['raw']);

    $listed = call("api/front/comments?content_id={$contentId}");
    $blockVisible = false;
    foreach ((array) ($listed['data']['list'] ?? []) as $top) {
        if (str_contains((string) ($top['content'] ?? ''), '刷单')) {
            $blockVisible = true;
        }
    }
    check('待审评论不对外可见', !$blockVisible, '待审评论出现在列表中');

    // 敏感词：2 级替换
    $repl = call('api/front/comment', ['content_id' => $contentId, 'content' => '需要加微信联系我'], $token);
    $saved = (string) Db::name('comment')->where('id', (int) ($repl['data']['id'] ?? 0))->value('content');
    check('敏感词 2 级被替换', !str_contains($saved, '加微信') && str_contains($saved, '***'), $saved);

    // 点赞
    $like = call("api/front/comment/{$topId}/like", [], $token);
    $lc1 = (int) Db::name('comment')->where('id', $topId)->value('like_count');
    check('点赞成功', ($like['code'] ?? null) === 0 && $lc1 === 1, "计数 {$lc1}");

    call("api/front/comment/{$topId}/like", [], $token);
    $lc2 = (int) Db::name('comment')->where('id', $topId)->value('like_count');
    check('重复点赞不涨计数', $lc2 === 1, "计数 {$lc2}");

    call("api/front/comment/{$topId}/like", null, $token, 'DELETE');
    $lc3 = (int) Db::name('comment')->where('id', $topId)->value('like_count');
    check('取消点赞计数 -1', $lc3 === 0, "计数 {$lc3}");

    // 举报
    $rp = call('api/front/report', ['target_type' => 'comment', 'target_id' => $topId, 'reason' => '内容不当'], $token);
    check('举报成功', ($rp['code'] ?? null) === 0, $rp['raw']);

    $rp2 = call('api/front/report', ['target_type' => 'comment', 'target_id' => $topId, 'reason' => '再次举报'], $token);
    check('重复举报被拒', ($rp2['code'] ?? null) !== 0, $rp2['raw']);

    // 先审后发
    Setting::set('comment', 'audit_first', '1');
    $audited = call('api/front/comment', ['content_id' => $contentId, 'content' => '先审后发测试评论'], $token);
    check('开启先审后发后新评论待审', ($audited['data']['status'] ?? null) === 0, $audited['raw']);
    Setting::set('comment', 'audit_first', '0');

    $final = call('api/front/comment', ['content_id' => $contentId, 'content' => '恢复后评论'], $token);
    check('关闭先审后发后恢复直接通过', ($final['data']['status'] ?? null) === 1, $final['raw']);

    // 帖子积分（M3.6 联动）：评论应产生积分流水
    $points = (int) Db::name('user_points_log')->where('user_id', $userId)->where('scene', 'comment')->count();
    check('评论产生积分流水', $points > 0, "流水 {$points} 条");
} finally {
    Db::name('comment_like')->whereIn('comment_id', Db::name('comment')->where('content_id', $contentId)->column('id'))->delete();
    Db::name('comment')->where('content_id', $contentId)->delete();
    Db::name('user_favorite')->where('user_id', $userId)->delete();
    Db::name('user_report')->where('user_id', $userId)->delete();
    Db::name('user_points_log')->where('user_id', $userId)->delete();
    Db::name('user_exp_log')->where('user_id', $userId)->delete();
    Db::name('content')->where('id', $contentId)->delete();
    Db::name('user')->where('id', $userId)->delete();
    echo str_repeat('-', 78) . "\n临时数据已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
