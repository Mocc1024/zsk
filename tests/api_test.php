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

// 14. 权限菜单树
$ruleTree = request('GET', $base . '/permission', [], $token);
show('权限菜单树', $ruleTree);
echo '    顶级菜单数：' . count($ruleTree['data']['list'] ?? []) . "\n";

// 15. 用户组：新增 / 授权 / 编辑 / 删除
$group = request('POST', $base . '/group', [
    'title'       => '测试运营组',
    'description' => '仅可查看内容列表',
    'rules'       => [21],
    'sort'        => 10,
    'status'      => 1,
], $token);
show('新增用户组', $group);
$groupId = $group['data']['id'] ?? 0;
if ($groupId) {
    show('分配权限', request('PUT', $base . '/group/' . $groupId . '/rules', ['rules' => [21, 31]], $token));
    $rules = request('GET', $base . '/group/' . $groupId . '/rules', [], $token);
    show('查看组权限', $rules);
    echo '    已授权规则ID：' . implode(',', $rules['data']['rule_ids'] ?? []) . "\n";
    show('编辑用户组', request('PUT', $base . '/group/' . $groupId, [
        'title'       => '测试运营组（改）',
        'description' => '改过的描述',
    ], $token));
    show('删除用户组', request('DELETE', $base . '/group/' . $groupId, [], $token));
}

// 16. 管理员列表
show('管理员列表', request('GET', $base . '/admin', [], $token));

// 17. 操作日志
show('操作日志', request('GET', $base . '/log?limit=5', [], $token));

// 18. 未授权访问
$unauthorized = request('GET', $base . '/content');
show('无令牌访问（应 401）', $unauthorized);

// 19. 普通管理员越权访问（应 403）
$editorLogin = request('POST', $base . '/auth/login', ['username' => 'editor', 'password' => 'editor123']);
$editorToken = $editorLogin['data']['token'] ?? '';
if ($editorToken) {
    show('编辑访问管理员列表（应 403）', request('GET', $base . '/admin', [], $editorToken));
    show('编辑访问用户组（应 403）', request('GET', $base . '/group', [], $editorToken));
}

// 20. RBAC 端到端：新建用户组 → 新建管理员加入该组 → 用新账号登录验证权限
$stamp = date('mdHis');
$rbacGroup = request('POST', $base . '/group', [
    'title'       => '自动化测试组' . $stamp,
    'description' => '仅授予内容查看权限，用于验证用户组授权生效',
    'rules'       => [21], // content.list
    'status'      => 1,
], $token);
show('RBAC-新建用户组', $rbacGroup);
$rbacGroupId = $rbacGroup['data']['id'] ?? 0;

if ($rbacGroupId) {
    $testerName = 'rbac' . $stamp;
    $rbacAdmin  = request('POST', $base . '/admin', [
        'username' => $testerName,
        'password' => 'rbactest123',
        'nickname' => 'RBAC测试账号',
        'group_id' => $rbacGroupId,
        'status'   => 1,
    ], $token);
    show('RBAC-新建管理员并分配组', $rbacAdmin);
    $rbacAdminId = $rbacAdmin['data']['id'] ?? 0;

    // 未指定用户组应被拒绝：管理员权限完全来自所属用户组，无组即无任何权限
    show('ADMIN-新增未选用户组（应失败）', request('POST', $base . '/admin', [
        'username' => 'nogroup' . $stamp,
        'password' => 'nogroup123',
        'nickname' => '未分组测试账号',
    ], $token));

    // 指向已禁用的用户组同样应被拒绝
    $disabledGroup = request('POST', $base . '/group', [
        'title'  => '禁用组' . $stamp,
        'rules'  => [21],
        'status' => 0,
    ], $token);
    if (!empty($disabledGroup['data']['id'])) {
        show('ADMIN-新增禁用组（应失败）', request('POST', $base . '/admin', [
            'username' => 'disgrp' . $stamp,
            'password' => 'disgrp123',
            'nickname' => '禁用组测试账号',
            'group_id' => $disabledGroup['data']['id'],
        ], $token));
        request('DELETE', $base . '/group/' . $disabledGroup['data']['id'], [], $token);
    }

    $testerLogin = request('POST', $base . '/auth/login', [
        'username' => $testerName,
        'password' => 'rbactest123',
    ]);
    show('RBAC-新账号登录', $testerLogin);
    $testerToken  = $testerLogin['data']['token'] ?? '';
    $testerPerms  = $testerLogin['data']['permissions'] ?? [];
    $testerMenus  = $testerLogin['data']['menus'] ?? [];

    if ($testerToken) {
        echo '    实际权限：' . implode(', ', array_map('strval', (array) $testerPerms)) . "\n";
        echo '    可见菜单：' . implode(' / ', array_map(static fn ($m) => $m['title'], $testerMenus)) . "\n";
        echo '    期望：仅 content.list，且只有「内容管理」菜单' . "\n";

        show('RBAC-访问有权限接口', request('GET', $base . '/content?limit=1', [], $testerToken));
        show('RBAC-访问无权限接口（应 403）', request('GET', $base . '/category', [], $testerToken));
        show('RBAC-访问用户组（应 403）', request('GET', $base . '/group', [], $testerToken));
    }

    // 内置超级管理员组不允许改权限（无论前端按钮还是接口调用）
    show('RBAC-超管组禁止改权限（应失败）', request('PUT', $base . '/group/1/rules', ['rules' => [21]], $token));

    // 清理测试数据
    if ($rbacAdminId) {
        show('RBAC-清理测试管理员', request('DELETE', $base . '/admin/' . $rbacAdminId, [], $token));
    }
    show('RBAC-清理测试用户组', request('DELETE', $base . '/group/' . $rbacGroupId, [], $token));
}

// 21. 内置初始超级管理员保护（id=1）
show('ADMIN-内置账号不可禁用（应失败）', request('PUT', $base . '/admin/1/status', ['status' => 0], $token));
show('ADMIN-内置账号不可删除（应失败）', request('DELETE', $base . '/admin/1', [], $token));
show('ADMIN-内置账号改组（应被忽略）', request('PUT', $base . '/admin/1', ['group_id' => 2], $token));
$builtinRow = null;
$check      = request('GET', $base . '/admin?limit=50', [], $token);
foreach (($check['data']['list'] ?? []) as $row) {
    if ((int) ($row['id'] ?? 0) === 1) {
        $builtinRow = $row;
    }
}
echo '    内置账号当前状态：status=' . ($builtinRow['status'] ?? '-')
    . ' group_id=' . ($builtinRow['group_id'] ?? '-')
    . ' 组名=' . ($builtinRow['group_title'] ?? '-') . "\n";
echo "    期望：仍然启用，且固定归属 group_id=1\n";

// 22. 退出
show('退出登录', request('POST', $base . '/auth/logout', [], $token));

echo "\n测试完成。\n";
