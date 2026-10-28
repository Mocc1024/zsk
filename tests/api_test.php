<?php
/**
 * 接口冒烟测试脚本
 * 用法：php tests/api_test.php [baseUrl]
 * 默认：http://127.0.0.1:8000/api
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000/api', '/');

function request(string $method, string $url, array $data = [], ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create([
        'http' => [
            'method'        => strtoupper($method),
            'header'        => implode("\r\n", $headers),
            'content'       => empty($data) ? '' : json_encode($data, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout'       => 20,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    return json_decode($raw ?: '{}', true) ?: ['raw' => $raw];
}

function show(string $title, array $resp): void
{
    $msg  = $resp['msg'] ?? '';
    $code = $resp['code'] ?? '-';
    echo sprintf("[%s] %-28s code=%s msg=%s\n", $code == 0 ? ' OK ' : 'FAIL', $title, $code, mb_substr((string) $msg, 0, 60));
}

// 1. 登录
$login = request('POST', $base . '/auth/login', ['username' => 'admin', 'password' => 'admin123']);
show('登录', $login);
$token = $login['data']['token'] ?? '';
if (!$token) {
    exit("登录失败，终止测试\n");
}

// 2. 当前用户
show('当前登录用户', request('GET', $base . '/auth/profile', [], $token));

// 3. 数据看板
$dash = request('GET', $base . '/dashboard', [], $token);
show('数据看板', $dash);
echo '    看板指标数：' . count($dash['data']['cards'] ?? []) . '，趋势天数：' . count($dash['data']['trend'] ?? []) . "\n";

// 4. 内容列表
$list = request('GET', $base . '/content?' . http_build_query([
    'page'    => 1,
    'limit'   => 5,
    'keyword' => '内容',
]), [], $token);
show('内容列表（搜索）', $list);
echo '    总数：' . ($list['data']['total'] ?? 0) . "\n";

// 5. 分类选项
$categories = request('GET', $base . '/category/options', [], $token);
show('分类选项', $categories);
$categoryId = $categories['data'][0]['id'] ?? 1;

// 6. 新增内容
$created = request('POST', $base . '/content', [
    'title'       => '接口自动化测试内容',
    'category_id' => $categoryId,
    'author'      => '测试脚本',
    'status'      => 1,
    'summary'     => '由 tests/api_test.php 创建',
    'content'     => '<p>自动化测试正文</p>',
    'tags'        => '测试,自动化',
    'sort'        => 1,
], $token);
show('新增内容', $created);
$contentId = $created['data']['id'] ?? 0;

if ($contentId) {
    // 7. 详情
    show('内容详情', request('GET', $base . '/content/' . $contentId, [], $token));

    // 8. 编辑
    show('编辑内容', request('PUT', $base . '/content/' . $contentId, [
        'title'       => '接口自动化测试内容（已编辑）',
        'category_id' => $categoryId,
        'author'      => '测试脚本',
        'status'      => 1,
        'summary'     => '编辑后的摘要',
        'content'     => '<p>编辑后的正文</p>',
    ], $token));

    // 9. 改状态
    show('设为下架', request('PUT', $base . '/content/' . $contentId . '/status', ['status' => 2], $token));

    // 10. 置顶
    show('设置置顶', request('PUT', $base . '/content/' . $contentId . '/top', ['is_top' => 1], $token));

    // 11. 删除
    show('删除内容', request('DELETE', $base . '/content/' . $contentId, [], $token));
}

// 12. 参数校验（标题为空）
show('新增内容-校验失败', request('POST', $base . '/content', ['title' => ''], $token));

// 13. 分类新增 / 编辑 / 删除
$cat = request('POST', $base . '/category', ['name' => '测试分类', 'slug' => 'test_cat', 'sort' => 5], $token);
show('新增分类', $cat);
$catId = $cat['data']['id'] ?? 0;
if ($catId) {
    show('编辑分类', request('PUT', $base . '/category/' . $catId, ['name' => '测试分类（改）', 'sort' => 6], $token));
    show('删除分类', request('DELETE', $base . '/category/' . $catId, [], $token));
}

// 14. 管理员列表
show('管理员列表', request('GET', $base . '/admin', [], $token));

// 15. 操作日志
show('操作日志', request('GET', $base . '/log?limit=5', [], $token));

// 16. 未授权访问
$unauthorized = request('GET', $base . '/content');
show('无令牌访问（应 401）', $unauthorized);

// 17. 退出
show('退出登录', request('POST', $base . '/auth/logout', [], $token));

echo "\n测试完成。\n";
