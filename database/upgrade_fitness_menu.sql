-- ============================================================
--  全民健身模块：一级栏目 + 5 个二级栏目 + 操作权限点
--  依赖：upgrade_fitness_v1.sql（业务表）
--  特性：固定 ID 段 600-699 + ON DUPLICATE KEY UPDATE，幂等可重跑
--  注意：只授权「权限点 ID」给用户组，绝不授予菜单 ID——
--        授予带子节点的菜单 ID 会递归放开其下全部权限点，造成提权。
--  执行：php scripts/run_sql.php database/upgrade_fitness_menu.sql
-- ============================================================

SET NAMES utf8mb4;

-- ---------------- 一级栏目：全民健身（sort=95，位于内容管理 90 之上） ----------------
INSERT INTO `zsk_auth_rule` (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`) VALUES
(600, 0,   1, '全民健身',   'fitness',              '/fitness',            'HeartOutlined', 95, 1, NOW(), NOW()),
(601, 600, 1, '统计管理',   'fitness.stat',         '/fitness/stat',       '',              95, 1, NOW(), NOW()),
(602, 600, 1, '门店管理',   'fitness.store',        '/fitness/store',      '',              94, 1, NOW(), NOW()),
(603, 600, 1, '教练管理',   'fitness.coach',        '/fitness/coach',      '',              93, 1, NOW(), NOW()),
(604, 600, 1, '会员管理',   'fitness.member',       '/fitness/member',     '',              92, 1, NOW(), NOW()),
(605, 600, 1, '企业管理',   'fitness.enterprise',   '/fitness/enterprise', '',              91, 1, NOW(), NOW())

-- 统计管理权限点
,(611, 601, 2, '查看统计',       'fitness.stat.view',            '', '', 10, 1, NOW(), NOW())

-- 门店管理权限点
,(620, 602, 2, '查看门店',       'fitness.store.list',           '', '', 10, 1, NOW(), NOW())
,(621, 602, 2, '新增门店',       'fitness.store.create',         '', '',  9, 1, NOW(), NOW())
,(622, 602, 2, '编辑门店',       'fitness.store.update',         '', '',  8, 1, NOW(), NOW())
,(623, 602, 2, '删除门店',       'fitness.store.delete',         '', '',  7, 1, NOW(), NOW())

-- 教练管理权限点
,(630, 603, 2, '查看教练',       'fitness.coach.list',           '', '', 10, 1, NOW(), NOW())
,(631, 603, 2, '加入教练',       'fitness.coach.create',         '', '',  9, 1, NOW(), NOW())
,(632, 603, 2, '编辑教练',       'fitness.coach.update',         '', '',  8, 1, NOW(), NOW())
,(633, 603, 2, '绑定门店',       'fitness.coach.bind-store',     '', '',  7, 1, NOW(), NOW())
,(634, 603, 2, '绑定学员',       'fitness.coach.bind-students',  '', '',  6, 1, NOW(), NOW())
,(635, 603, 2, '绩效管理',       'fitness.coach.performance',    '', '',  5, 1, NOW(), NOW())
,(636, 603, 2, '教练离职',       'fitness.coach.resign',         '', '',  4, 1, NOW(), NOW())

-- 会员管理权限点
,(640, 604, 2, '查看会员',       'fitness.member.list',          '', '', 10, 1, NOW(), NOW())
,(641, 604, 2, '新增会员',       'fitness.member.create',        '', '',  9, 1, NOW(), NOW())
,(642, 604, 2, '编辑会员',       'fitness.member.update',        '', '',  8, 1, NOW(), NOW())
,(643, 604, 2, '消课',           'fitness.member.consume',       '', '',  7, 1, NOW(), NOW())
,(644, 604, 2, '会员卡管理',     'fitness.member.card',          '', '',  6, 1, NOW(), NOW())
,(645, 604, 2, '删除会员',       'fitness.member.delete',        '', '',  5, 1, NOW(), NOW())

-- 企业管理权限点
,(650, 605, 2, '查看企业',       'fitness.enterprise.list',          '', '', 10, 1, NOW(), NOW())
,(651, 605, 2, '新增企业',       'fitness.enterprise.create',        '', '',  9, 1, NOW(), NOW())
,(652, 605, 2, '编辑企业',       'fitness.enterprise.update',        '', '',  8, 1, NOW(), NOW())
,(653, 605, 2, '绑定学员',       'fitness.enterprise.bind-students', '', '',  7, 1, NOW(), NOW())
,(654, 605, 2, '删除企业',       'fitness.enterprise.delete',        '', '',  6, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `pid` = VALUES(`pid`), `type` = VALUES(`type`), `title` = VALUES(`title`),
  `name` = VALUES(`name`), `path` = VALUES(`path`), `icon` = VALUES(`icon`),
  `sort` = VALUES(`sort`), `status` = VALUES(`status`), `update_time` = NOW();

-- ---------------- 授权 ----------------
-- 超级管理员组 rules='*'，自动拥有全部权限，无需处理。
-- 其他用户组请登录后台「系统管理 → 权限菜单 / 用户组管理」自行勾选上述权限点 ID。
-- 注意：勾选时只选 type=2 的权限点（611、620~623、630~636、640~645、650~654），
--       不要勾选菜单 ID（600~605），否则会把整个栏目全部放开。

-- ============================================================
--  回滚（默认注释，按需手工执行；执行前请备份）
-- DELETE FROM `zsk_auth_rule` WHERE `id` BETWEEN 600 AND 699;
-- ============================================================
