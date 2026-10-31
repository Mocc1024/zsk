<?php
declare (strict_types = 1);

/**
 * 权限边界探针（M3.7 权限矩阵的验收）
 *
 * 为什么要单独一个探针：test_admin_manage.php 全程用超级管理员（rules='*'），
 * 天然绕过所有权限校验。历史上「运营能审评论却开不了先审后发」的 403 缺陷
 * 就是这么漏掉的 —— 功能探针全绿，一换受限账号就崩。
 *
 * 这里反过来：用三个不同用户组的账号打同一批接口，
 * 「该放行的是不是真放行了」和「该拦住的是不是真拦住了」都断言一遍。
 *
 * 用法（需先启动后端服务）：
 *   php think run --host 127.0.0.1 --port 8000
 *   php scripts/test_permission_boundary.php
 *
 * 退出码：全部通过 0，任一失败 1
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

const BASE = 'http://127.0.0.1:8000';
const PASS = 'Probe@123456';

/** 不存在的资源 id：用来测写操作权限，又不想真的改动数据 */
const MISSING_ID = 999999;

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
        'msg'  => $json['msg'] ?? '',
        'raw'  => $raw,
    ];
}

$results = [];
function check(string $name, bool $ok, string $detail = '')
{
    global $results;
    $results[] = [$ok, $name];
    printf("%s %-24s %-18s %s\n", $ok ? '[PASS]' : '[FAIL]', $name, '', $ok ? '' : '← ' . $detail);
}

/**
 * 权限矩阵
 *
 * [方法, 路径, 请求体, 权限点, 说明, 内容运营是否应有权限, 只读访客是否应有权限]
 *
 * 请求体的取法：写操作一律用「不存在的 id / 非法参数」，
 * 这样有权限时会被业务校验拦下（404 或参数错误），但绝不会真的改动数据。
 * 只读访客按设计只开放 101/108/121/125/141 这几个「查看」类。
 */
$cases = [
    ['GET',    'api/user-manage',                       null,                 'user.list',           '用户列表',     true,  true],
    ['POST',   'api/user-manage',                       ['username' => ''],   'user.create',         '新建用户',     true,  false],
    ['GET',    'api/user-manage/' . MISSING_ID,         null,                 'user.view',           '用户详情',     true,  false],
    ['PUT',    'api/user-manage/' . MISSING_ID,         ['nickname' => 'x'],  'user.update',         '编辑用户',     true,  false],
    ['PUT',    'api/user-manage/' . MISSING_ID . '/status', ['status' => 1],  'user.status',         '启用/禁用',    true,  false],
    ['POST',   'api/user-manage/' . MISSING_ID . '/reset-password', [],       'user.reset-password', '重置密码',     false, false],
    ['POST',   'api/user-manage/' . MISSING_ID . '/points', ['points' => 0],  'user.points',         '调整积分',     true,  false],
    ['POST',   'api/user-manage/' . MISSING_ID . '/exp',    ['exp' => 0],     'user.exp',            '调整经验',     true,  false],

    ['GET',    'api/level',                             null,                 'level.list',          '等级列表',     true,  true],
    ['POST',   'api/level',                             [],                   'level.create',        '新增等级',     false, false],

    ['GET',    'api/comment-audit',                     null,                 'comment.list',        '评论审核列表', true,  true],
    ['PUT',    'api/comment-audit/' . MISSING_ID . '/audit', ['result' => 1], 'comment.audit',       '审核评论',     true,  false],
    ['DELETE', 'api/comment-audit/' . MISSING_ID,       null,                 'comment.delete',      '删除评论',     true,  false],
    ['GET',    'api/comment-audit/config',              null,                 'comment.config',      '评论配置',     true,  false],
    ['GET',    'api/sensitive-word',                    null,                 'comment.config',      '敏感词库',     true,  false],

    ['GET',    'api/report',                            null,                 'report.list',         '举报列表',     true,  true],
    ['PUT',    'api/report/' . MISSING_ID . '/handle',  ['result' => 1],      'report.handle',       '处理举报',     true,  false],

    ['GET',    'api/points/rule',                       null,                 'points.rule',         '积分规则',     true,  false],
    ['GET',    'api/points/stat',                       null,                 'points.stat',         '积分统计',     true,  false],

    ['GET',    'api/open-app',                          null,                 'open.list',           '开放应用列表', true,  true],
    ['POST',   'api/open-app',                          [],                   'open.create',         '新增应用',     false, false],
];

$stamp = date('mdHis');

// 组 id => [展示名, 取矩阵哪一列（null 表示全部放行）]
$groups = [
    1 => ['超级管理员 rules=*', null],
    2 => ['内容运营',           5],
    3 => ['只读访客',           6],
];

Db::name('admin')->whereLike('username', 'probe_rbac_%')->delete();

$admins = [];
try {
    foreach ($groups as $groupId => [$groupName, $col]) {
        $uname = 'probe_rbac_' . $groupId . '_' . $stamp;

        $adminId = (int) Db::name('admin')->insertGetId([
            'username'    => $uname,
            'password'    => password_hash(PASS, PASSWORD_BCRYPT),
            'nickname'    => '权限边界探针',
            'group_id'    => $groupId,
            'status'      => 1,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        Db::name('auth_group_access')->insert(['uid' => $adminId, 'group_id' => $groupId]);

        $login = call('api/auth/login', ['username' => $uname, 'password' => PASS]);
        if (($login['code'] ?? null) !== 0 || empty($login['data']['token'])) {
            fwrite(STDERR, '登录失败（组 ' . $groupId . '）：' . $login['raw'] . PHP_EOL);
            exit(1);
        }

        $admins[$adminId] = $groupId;
        $token            = (string) $login['data']['token'];

        echo str_repeat('-', 78) . "\n{$groupName}\n" . str_repeat('-', 78) . "\n";

        foreach ($cases as $case) {
            [$method, $path, $body, $perm, $name] = $case;

            // 超管没有对应列，一律期望放行
            $expectAllowed = $col === null ? true : (bool) $case[$col];

            $res  = call($path, $body, $token, $method);
            $code = $res['code'];
            $got403 = $code === 403;

            $ok = $expectAllowed ? !$got403 : $got403;

            check(
                $name . ' [' . $perm . ']',
                $ok,
                sprintf(
                    '期望%s，实际 code=%s msg=%s',
                    $expectAllowed ? '放行' : '403',
                    $code ?? '-',
                    $res['msg']
                )
            );
        }
        echo "\n";
    }
} finally {
    foreach (array_keys($admins) as $id) {
        Db::name('auth_group_access')->where('uid', $id)->delete();
        Db::name('admin')->where('id', $id)->delete();
    }
    echo str_repeat('-', 78) . "\n临时管理员已删除\n";
}

$failed = 0;
foreach ($results as [$ok]) {
    $failed += $ok ? 0 : 1;
}
printf("完成：通过 %d 项，失败 %d 项\n", count($results) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
