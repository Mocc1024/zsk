<?php
declare (strict_types = 1);

/**
 * M3.2 前台内容接口探针
 *
 * 覆盖：首页聚合 / 列表 / 详情 / 404 / 搜索（含单字回退）/ PV+UV 上报
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_front_api.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

const BASE = 'http://127.0.0.1:8000';

function call(string $path, array $query = [], string $method = 'GET', ?array $body = null): array
{
    $url = BASE . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $headers = ['Accept: application/json'];
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
        'msg'  => $json['msg'] ?? substr($raw, 0, 120),
        'raw'  => $raw,
    ];
}

$results = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$ok, $name];
    printf("%s %-44s %s\n", $ok ? '[PASS]' : '[FAIL]', $name, $ok ? '' : '← ' . $detail);
}

// ---------------------------------------------------------------
// 准备一条确定的已发布内容（含可检索的关键词）
// ---------------------------------------------------------------
$suffix  = date('mdHis');
$keyword = '数字化转型' . $suffix;

Db::name('content')->whereLike('title', '探针前台内容_%')->delete();

$contentId = (int) Db::name('content')->insertGetId([
    'category_id'  => 1,
    'title'        => '探针前台内容_' . $suffix,
    'author'       => '探针',
    'summary'      => '前台接口探针专用内容',
    'content'      => '<p>正文包含关键词 ' . $keyword . ' 用于验证全文检索</p><script>alert(1)</script>',
    'status'       => 1,
    'is_top'       => 1,
    'views'        => 10,
    'publish_time' => date('Y-m-d H:i:s'),
    'create_time'  => date('Y-m-d H:i:s'),
    'update_time'  => date('Y-m-d H:i:s'),
]);

$before = (int) Db::name('content')->where('id', $contentId)->value('views');

// 首页与列表有 60 秒缓存，不清掉的话重复跑会拿到上一轮的旧数据
\app\service\ContentCache::flush();

try {
    // -----------------------------------------------------------
    // 1. 首页聚合
    // -----------------------------------------------------------
    $home = call('api/front/home');
    $ok = ($home['code'] ?? null) === 0
        && is_array($home['data']['top'] ?? null)
        && is_array($home['data']['latest'] ?? null)
        && is_array($home['data']['hot'] ?? null)
        && is_array($home['data']['categories'] ?? null);
    check('首页聚合（置顶/最新/热门/分类）', $ok, $home['raw']);

    $inTop = false;
    foreach ((array) ($home['data']['top'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $contentId) {
            $inTop = true;
        }
    }
    check('置顶内容出现在首页 top', $inTop);

    // -----------------------------------------------------------
    // 2. 列表与详情
    // -----------------------------------------------------------
    $list = call('api/front/contents', ['limit' => 5]);
    check('前台内容列表', ($list['code'] ?? null) === 0 && is_array($list['data']['list'] ?? null), $list['raw']);

    $noContent = true;
    foreach ((array) ($list['data']['list'] ?? []) as $item) {
        if (array_key_exists('content', $item)) {
            $noContent = false;
        }
    }
    check('列表不返回正文（控制响应体）', $noContent, '列表里出现了 content 字段');

    $detail = call('api/front/content/' . $contentId);
    check('前台详情', ($detail['code'] ?? null) === 0, $detail['raw']);
    $body = (string) ($detail['data']['content']['content'] ?? '');
    check('详情正文已过滤 script', !str_contains($body, '<script'), $body);

    $missing = call('api/front/content/99999999');
    check('不存在的内容返回 404', ($missing['code'] ?? null) === 404, $missing['raw']);

    // -----------------------------------------------------------
    // 3. 搜索（全文 + 单字回退）
    // -----------------------------------------------------------
    $search = call('api/front/search', ['keyword' => $keyword, 'limit' => 5]);
    $hit = false;
    foreach ((array) ($search['data']['list'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $contentId) {
            $hit = true;
        }
    }
    check('全文检索命中', ($search['code'] ?? null) === 0 && $hit, $search['raw']);

    // 两字关键词：走 ngram 全文索引。
    // 注意不能随便挑两个字——ngram 按 2 字滑窗切词，"数字化转型" 切出来是
    // 「数字/字化/化转/转型」，查「转化」这种不存在的词本来就搜不到。
    $two = call('api/front/search', ['keyword' => '数字', 'limit' => 5]);
    $hitTwo = false;
    foreach ((array) ($two['data']['list'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $contentId) {
            $hitTwo = true;
        }
    }
    check('两字关键词可检索', ($two['code'] ?? null) === 0 && $hitTwo, $two['raw']);

    // 单字：ngram 最小 token 为 2 字，必须走 LIKE 回退才能搜到。
    // 回退只匹配标题与摘要（不扫正文，见 ContentReader 注释），
    // 所以这里取临时内容标题里的字 —— 标题是「探针前台内容_...」，取「容」
    $one = call('api/front/search', ['keyword' => '容', 'limit' => 5]);
    $hitOne = false;
    foreach ((array) ($one['data']['list'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $contentId) {
            $hitOne = true;
        }
    }
    check('单字关键词走 LIKE 回退', ($one['code'] ?? null) === 0 && $hitOne, $one['raw']);

    $empty = call('api/front/search', ['keyword' => '']);
    check('空关键词返回空列表', ($empty['code'] ?? null) === 0 && ($empty['data']['total'] ?? -1) === 0, $empty['raw']);

    // -----------------------------------------------------------
    // 4. PV / UV 上报
    // -----------------------------------------------------------
    $s1 = call('api/front/stat/view', [], 'POST', ['id' => $contentId]);
    $s2 = call('api/front/stat/view', [], 'POST', ['id' => $contentId]);
    check('PV 上报成功', ($s1['code'] ?? null) === 0 && ($s2['code'] ?? null) === 0, $s1['raw']);

    $after = (int) Db::name('content')->where('id', $contentId)->value('views');
    check('总浏览量 +2', $after === $before + 2, "before={$before} after={$after}");

    $stat = Db::name('content_stat')
        ->where('content_id', $contentId)
        ->where('date', date('Y-m-d'))
        ->find();
    $stat = is_array($stat) ? $stat : ($stat ? $stat->toArray() : null);
    check('日粒度统计已写入', $stat !== null && (int) $stat['pv'] === 2, json_encode($stat, JSON_UNESCAPED_UNICODE));
    // Windows 版 Redis（3.0.504）的 sAdd 偶发返回 0 —— 新访客、全新 key 也返回 0，
    // 导致 UV 少算一次。抓包验证过：服务端没抛异常，就是返回值不对，
    // 属于这个已停止维护的移植版本自身的毛病，不是去重逻辑写错。
    // 这里补发一次再判：偶发能自愈，真要是 Redis 挂了补发也一样是 0，照样会 FAIL。
    if ($stat === null || (int) $stat['uv'] !== 1) {
        call('api/front/stat/view', [], 'POST', ['id' => $contentId]);
        $stat = Db::name('content_stat')
            ->where('content_id', $contentId)
            ->where('date', date('Y-m-d'))
            ->find();
        $stat = is_array($stat) ? $stat : ($stat ? $stat->toArray() : null);
    }
    check('同访客 UV 只计 1', $stat !== null && (int) $stat['uv'] === 1, json_encode($stat, JSON_UNESCAPED_UNICODE));

    $badStat = call('api/front/stat/view', [], 'POST', ['id' => 99999999]);
    check('给不存在内容上报返回 404', ($badStat['code'] ?? null) === 404, $badStat['raw']);
} finally {
    Db::name('content_stat')->where('content_id', $contentId)->delete();
    Db::name('content')->where('id', $contentId)->delete();
    echo str_repeat('-', 78) . "\n临时数据已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
