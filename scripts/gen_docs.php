<?php
/**
 * 归档文档生成器
 *
 * 从「数据库真实结构 + 路由文件 + 权限映射」三处事实来源自动生成文档，
 * 避免文档与代码脱节。改动表结构、路由或权限点后重跑一次即可同步。
 *
 * 用法：php scripts/gen_docs.php
 * 输出：docs/数据库字典.md、docs/权限清单.md、docs/接口文档.md
 */

declare (strict_types = 1);

$root = dirname(__DIR__);

/** 读取 .env */
$env = [];
if (is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

$prefix = $env['DB_PREFIX'] ?? '';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '3306',
        $env['DB_NAME'] ?? ''
    ),
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stamp = date('Y-m-d H:i:s');
$docs  = $root . '/docs';
if (!is_dir($docs)) {
    mkdir($docs, 0755, true);
}

// =====================================================================
// 1. 数据库字典
// =====================================================================
$statuses = $pdo->query('SHOW TABLE STATUS')->fetchAll();
$tables   = [];
foreach ($statuses as $row) {
    if ($prefix !== '' && !str_starts_with($row['Name'], $prefix)) {
        continue;
    }
    $tables[] = $row;
}
usort($tables, static fn (array $a, array $b): int => strcmp($a['Name'], $b['Name']));

$out = <<<MD
# 数据库字典

> 本文件由 `php scripts/gen_docs.php` **自动生成**，请勿手工编辑。
> 生成时间：{$stamp}　表前缀：`{$prefix}`　字符集：utf8mb4
> 事实来源是数据库真实结构，改表后重跑脚本即可同步。

## 一、表概览

| 表名 | 说明 | 字段数 | 行数（估值） |
| --- | --- | --- | --- |

MD;

foreach ($tables as $table) {
    $columns = $pdo->query('SHOW FULL COLUMNS FROM `' . $table['Name'] . '`')->fetchAll();
    $out .= sprintf(
        "| `%s` | %s | %d | %s |\n",
        $table['Name'],
        $table['Comment'] !== '' ? $table['Comment'] : '—',
        count($columns),
        number_format((int) ($table['Rows'] ?? 0))
    );
}

$out .= "\n## 二、表结构明细\n";

foreach ($tables as $table) {
    $columns = $pdo->query('SHOW FULL COLUMNS FROM `' . $table['Name'] . '`')->fetchAll();
    $indexes = $pdo->query('SHOW INDEX FROM `' . $table['Name'] . '`')->fetchAll();

    $out .= "\n### `" . $table['Name'] . "`\n\n";
    if ($table['Comment'] !== '') {
        $out .= '**说明**：' . $table['Comment'] . "\n\n";
    }

    $out .= "| 字段 | 类型 | 可空 | 默认值 | 注释 |\n| --- | --- | --- | --- | --- |\n";
    foreach ($columns as $col) {
        $default = $col['Default'] === null ? 'NULL' : ($col['Default'] === '' ? "''" : $col['Default']);
        $out .= sprintf(
            "| `%s` | %s | %s | %s | %s |\n",
            $col['Field'],
            $col['Type'],
            $col['Null'] === 'YES' ? '是' : '否',
            $default,
            $col['Comment'] !== '' ? $col['Comment'] : '—'
        );
    }

    $grouped = [];
    foreach ($indexes as $idx) {
        $grouped[$idx['Key_name']]['unique']   = (int) $idx['Non_unique'] === 0;
        $grouped[$idx['Key_name']]['columns'][] = $idx['Column_name'];
    }

    if ($grouped) {
        $out .= "\n**索引**\n\n| 索引名 | 字段 | 类型 |\n| --- | --- | --- |\n";
        foreach ($grouped as $name => $info) {
            $type = $name === 'PRIMARY' ? '主键' : ($info['unique'] ? '唯一' : '普通');
            $out .= sprintf("| `%s` | %s | %s |\n", $name, implode(', ', $info['columns']), $type);
        }
    }
}

file_put_contents($docs . '/数据库字典.md', $out);
echo '已生成 docs/数据库字典.md（' . count($tables) . " 张表）\n";

// =====================================================================
// 2. 权限清单
// =====================================================================
$rules  = $pdo->query('SELECT id, pid, type, title, name, path, status FROM `' . $prefix . 'auth_rule` WHERE delete_time IS NULL ORDER BY sort DESC, id ASC')->fetchAll();
$groups = $pdo->query('SELECT id, title, rules, status FROM `' . $prefix . 'auth_group` WHERE delete_time IS NULL ORDER BY id ASC')->fetchAll();

/** 按 pid 递归展平，输出树形缩进 */
$byPid = [];
foreach ($rules as $rule) {
    $byPid[(int) $rule['pid']][] = $rule;
}

$lines = [];
$walk  = function (int $pid, int $depth) use (&$walk, &$lines, $byPid): void {
    foreach ($byPid[$pid] ?? [] as $rule) {
        $indent = str_repeat('　', $depth) . ($depth > 0 ? '└ ' : '');
        $type   = (int) $rule['type'] === 1 ? '菜单' : '权限点';
        $state  = (int) $rule['status'] === 1 ? '' : '（已禁用）';
        $lines[] = sprintf(
            '| %d | %s%s | %s | `%s` | %s | %s%s |',
            $rule['id'],
            $indent,
            $rule['title'],
            $type,
            $rule['name'],
            $rule['path'] !== '' ? '`' . $rule['path'] . '`' : '—',
            (int) $rule['status'] === 1 ? '启用' : '禁用',
            $state
        );
        $walk((int) $rule['id'], $depth + 1);
    }
};
$walk(0, 0);

/** 组的 rules（可含菜单 ID）展开为权限点标识 */
$byId = array_column($rules, null, 'id');
$expand = function (array $ids) use (&$expand, $byId): array {
    $owned = [];
    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            continue;
        }
        $owned[$id] = true;
        foreach ($byId as $child) {
            if ((int) $child['pid'] === (int) $id) {
                $owned = $owned + array_fill_keys($expand([(int) $child['id']]), true);
                $owned[(int) $child['id']] = true;
            }
        }
    }
    return array_keys($owned);
};

$groupLines = [];
foreach ($groups as $group) {
    $rulesField = (string) $group['rules'];
    if ($rulesField === '*') {
        $keys = ['*（全部权限）'];
    } else {
        $ids  = array_map('intval', array_filter(explode(',', $rulesField)));
        $keys = [];
        foreach ($expand($ids) as $rid) {
            if (isset($byId[$rid]) && (int) $byId[$rid]['type'] === 2 && $byId[$rid]['name'] !== '') {
                $keys[] = $byId[$rid]['name'];
            }
        }
        sort($keys);
    }

    $groupLines[] = sprintf(
        "| %d | %s | %s | %s | %d 项 |",
        $group['id'],
        $group['title'],
        $rulesField === '*' ? '*' : '`' . $rulesField . '`',
        $keys ? implode('、', array_map(static fn ($k) => '`' . $k . '`', $keys)) : '—',
        count($keys)
    );
}

$perm = <<<MD
# 权限清单

> 本文件由 `php scripts/gen_docs.php` **自动生成**，请勿手工编辑。
> 生成时间：{$stamp}
> 权限唯一来源：`{$prefix}auth_group.rules`（权限菜单 ID 集合）。
> 授权时勾选父级菜单，即自动展开其下全部权限点（见 `Permission::keysByRuleIds()`）。

## 一、权限树

| ID | 名称 | 类型 | 标识 | 前端路由 | 状态 |
| --- | --- | --- | --- | --- | --- |

MD;

$perm .= implode("\n", $lines) . "\n";

$perm .= <<<MD

## 二、用户组默认授权

| 组 ID | 名称 | rules 字段 | 展开后的权限点 | 数量 |
| --- | --- | --- | --- | --- |

MD;

$perm .= implode("\n", $groupLines) . "\n";

$perm .= <<<MD

## 三、鉴权链路

1. `app/middleware/Auth`：校验 JWT，解析管理员与权限，写入 `Context`
2. `Permission::matchRoute()`：按 `config/auth_route.php` 把「方法 + 路径」映射为权限点
3. `Permission::can()` / `BaseController::checkPermission()`：无权限抛 403
4. 控制器内的**字段级**校验：`checkFieldChange()`（如 `content.status`、`content.top`）
5. 超级管理员（所属组 `rules='*'`）与内置账号 `id=1` 直接放行

> 未出现在 `config/auth_route.php` 的接口默认放行（如 `auth/profile`、`auth/logout`），
> 新增写操作接口时**必须**补登记，否则会形成越权缺口。

MD;

file_put_contents($docs . '/权限清单.md', $perm);
echo '已生成 docs/权限清单.md（' . count($rules) . ' 条规则、' . count($groups) . " 个用户组）\n";

// =====================================================================
// 3. 接口文档
// =====================================================================
$routeSrc = file_get_contents($root . '/route/app.php');
preg_match_all("#Route::(get|post|put|delete)\(\s*'([^']+)'\s*,\s*'([^']+)'#", $routeSrc, $matched, PREG_SET_ORDER);

$authMap = [];
foreach ((array) require $root . '/config/auth_route.php' as $item) {
    $authMap[strtoupper($item[0]) . ' ' . $item[1]] = $item[2];
}

$moduleOf = [
    'AuthController'       => '登录认证',
    'DashboardController'  => '数据看板',
    'ContentController'    => '内容管理',
    'UploadController'     => '文件上传',
    'AttachmentController' => '素材库',
    'AttachmentFolderController' => '素材分组',
    'TagController'        => '标签管理',
    'CategoryController'   => '分类管理',
    'AuthRuleController'   => '权限菜单',
    'AuthGroupController'  => '用户组管理',
    'AdminController'      => '管理员管理',
    'LogController'        => '操作日志',
];

$rows = [];
foreach ($matched as $m) {
    $method  = strtoupper($m[1]);
    $path    = 'api/' . $m[2];
    $handler = $m[3];
    $parts   = explode('@', str_replace('\\app\\controller\\', '', $handler));
    $ctrl    = $parts[0] ?? '';
    $action  = $parts[1] ?? '';

    $rows[] = [
        'module'   => $moduleOf[$ctrl] ?? $ctrl,
        'method'   => $method,
        'path'     => '/' . $path,
        'perm'     => $authMap[$method . ' ' . $path] ?? '',
        'handler'  => $ctrl . '@' . $action,
    ];
}

// 按模块顺序输出
$order = array_values(array_unique(array_column($rows, 'module')));
$priorities = ['登录认证', '数据看板', '内容管理', '文件上传', '素材分组', '素材库', '标签管理', '分类管理', '权限菜单', '用户组管理', '管理员管理', '操作日志'];
usort($order, static function (string $a, string $b) use ($priorities): int {
    $ia = array_search($a, $priorities, true);
    $ib = array_search($b, $priorities, true);
    return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
});

$api = <<<MD
# 接口文档

> 本文件的「接口清单」由 `php scripts/gen_docs.php` **自动生成**（源：`route/app.php` + `config/auth_route.php`），
> 第二章「通用约定」与第三章「核心接口示例」为手工维护。生成时间：{$stamp}

## 一、接口清单

| 方法 | 路径 | 权限点 | 处理器 |
| --- | --- | --- | --- |

MD;

foreach ($order as $module) {
    $api .= "\n**" . $module . "**\n\n";
    foreach ($rows as $row) {
        if ($row['module'] !== $module) {
            continue;
        }
        $api .= sprintf(
            "| `%s` | `%s` | %s | `%s` |\n",
            $row['method'],
            $row['path'],
            $row['perm'] !== '' ? '`' . $row['perm'] . '`' : '—（默认放行）',
            $row['handler']
        );
    }
}

$api .= <<<MD

## 二、通用约定

### 2.1 请求

- 基础路径：`/api`（部署到 `www.zsk.com/admin/` 时由 Nginx 转发到 PHP）
- 编码：`application/json`；上传接口用 `multipart/form-data`
- 鉴权：除 `POST /api/auth/login` 外，全部接口需携带 `Authorization: Bearer <token>`
- 令牌有效期 7200 秒，401 时前端会清令牌并跳转登录页

### 2.2 响应

统一信封：

```json
{ "code": 0, "msg": "操作成功", "data": {} }
```

- `code = 0` 成功；非 0 为业务失败，`msg` 是可直接展示给用户的中文提示
- HTTP 状态码与 `code` 一致：`401` 未登录 / `403` 无权限 / `404` 资源不存在

### 2.3 分页

列表接口统一接受 `page`（默认 1）与 `limit`（默认 10，上限 200），返回：

```json
{ "code": 0, "data": { "list": [], "total": 0, "page": 1, "pageSize": 10 } }
```

### 2.4 常见错误码

| code | 含义 | 常见场景 |
| --- | --- | --- |
| 0 | 成功 | — |
| 1 | 业务失败 | 参数校验不通过、被引用无法删除等 |
| 401 | 未登录/令牌失效 | 令牌过期、被篡改 |
| 403 | 无权限 | 当前用户组未授权该权限点 |
| 404 | 资源不存在 | ID 不存在或已软删除 |
| 500 | 服务异常 | 代码缺陷，需查日志 |

## 三、核心接口示例

### 3.1 登录

```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'
```

返回 `data.token`、`data.admin`、`data.permissions`、`data.menus`。

### 3.2 上传图片（编辑器 / 素材库共用）

```bash
curl -X POST http://127.0.0.1:8000/api/upload/image \
  -H "Authorization: Bearer <token>" \
  -F "file=@a.jpg" -F "folder=editor"
```

大小上限：图片 5MB / 视频 50MB / 附件 20MB（与前端 `UPLOAD_LIMITS` 一致，服务端二次校验）。
`folder` 不存在时自动登记为素材分组。

### 3.3 素材分组

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/attachment/folders` | 分组列表（含每个分组的素材数量）与素材总数 |
| POST | `/api/attachment/folders` | 新增分组（名称唯一，最长 30 字符） |
| PUT | `/api/attachment/folders/:id` | 重命名 / 改排序，重命名会同步改写其下素材 |
| DELETE | `/api/attachment/folders/:id` | 删除分组，其下素材统一移入 `default`（`default` 不可删） |
| PUT | `/api/attachment/move` | 批量移动素材 `{ ids: [1,2], folder: "cover" }` |

### 3.4 回收站

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/content/trash` | 回收站列表 |
| GET | `/api/content/trash/:id` | 回收站内容详情（只读预览，含正文与标签） |
| POST | `/api/content/:id/restore` | 恢复（正文、标签全部保留） |
| POST | `/api/content/:id/force-delete` | 彻底删除（连带标签关联） |

> 内容版本（原 3.5 节的版本列表 / 详情 / 回滚）已于 2026.10.30 下线删除，
> 接口与 `zsk_content_version` 表不再使用，清理脚本见 `database/upgrade_drop_content_version.sql`。

## 四、回归脚本

- `scripts/test_html_filter.php`：XSS 与样式净化回归（39 条用例，双引擎），退出码非 0 即失败
- `scripts/run_sql.php <sql>`：执行迁移 SQL（Windows 无 mysql 客户端时使用）
- `scripts/gen_docs.php`：重新生成本文档、数据库字典与权限清单
- `scripts/measure_edit_efficiency.php`：编辑效率度量

MD;

file_put_contents($docs . '/接口文档.md', $api);
echo '已生成 docs/接口文档.md（' . count($rows) . " 条接口）\n";
