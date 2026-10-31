-- =============================================================
--  第三阶段（M3.0）权限点升级：新增菜单与权限点
--  依赖：database/auth_rbac.sql 与 upgrade_permission_p2.sql 已执行
--
--  特性：
--    · 按固定 ID 写入，ON DUPLICATE KEY UPDATE 保证可重复执行
--    · 不覆盖 status 字段（管理员手工启停不会被重置）
--    · 用户组授权用 FIND_IN_SET 判重，重复执行不会追加重复 ID
--    · rules='*' 的超级管理员组自动跳过，不受影响
--
--  ID 分配（避开既有 10/20/40/45/50 与 28/29/30/201/451 等）：
--    100 用户管理（子 101-110）　120 互动管理（子 121-128）　140 开放平台（子 141-145）
--
--  ⚠ 授权纪律（第二阶段 11.14 教训）：
--    本文件只授予「具体权限点 ID」，不授予菜单 ID ——
--    授予菜单会自动展开其下全部权限点，容易把只读角色提权。
--
--  配套工作（本文件不做，M3.7 落地时补）：
--    后台接口需在 config/auth_route.php 增加映射，例如
--      'api/user-manage'      → user.list
--      'api/comment-audit'    → comment.list
--      'api/open-app'         → open.list
--    否则接口级中间件无法按「请求方法 + 路径」匹配权限点。
--
--  回滚语句见文件末尾（默认注释，需手工执行）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. 新增菜单与权限点
-- =========================================================
INSERT INTO `zsk_auth_rule` (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`) VALUES
-- 用户管理（顶级菜单）
(100, 0,  1, '用户管理',     'user',                 '/user',       'TeamOutlined',    65, 1, NOW(), NOW()),
(101, 100, 2, '查看用户',     'user.list',           '',            '',                10, 1, NOW(), NOW()),
(111, 100, 2, '新增用户',     'user.create',         '',            '',                 9, 1, NOW(), NOW()),
(102, 100, 2, '用户详情',     'user.view',           '',            '',                 9, 1, NOW(), NOW()),
(103, 100, 2, '编辑用户',     'user.update',         '',            '',                 8, 1, NOW(), NOW()),
(104, 100, 2, '启用/禁用',    'user.status',         '',            '',                 7, 1, NOW(), NOW()),
(105, 100, 2, '重置密码',     'user.reset-password', '',            '',                 6, 1, NOW(), NOW()),
(106, 100, 2, '调整积分',     'user.points',         '',            '',                 5, 1, NOW(), NOW()),
(107, 100, 2, '调整经验',     'user.exp',            '',            '',                 4, 1, NOW(), NOW()),
(108, 100, 2, '查看等级规则', 'level.list',          '',            '',                 3, 1, NOW(), NOW()),
(109, 100, 2, '新增等级',     'level.create',        '',            '',                 2, 1, NOW(), NOW()),
(110, 100, 2, '编辑等级',     'level.update',        '',            '',                 1, 1, NOW(), NOW()),
-- 互动管理（顶级菜单）
(120, 0,  1, '互动管理',     'interact',            '/interact',   'MessageOutlined', 64, 1, NOW(), NOW()),
(121, 120, 2, '查看评论',     'comment.list',        '',            '',                10, 1, NOW(), NOW()),
(122, 120, 2, '评论审核',     'comment.audit',       '',            '',                 9, 1, NOW(), NOW()),
(123, 120, 2, '删除评论',     'comment.delete',      '',            '',                 8, 1, NOW(), NOW()),
(124, 120, 2, '评论配置',     'comment.config',      '',            '',                 7, 1, NOW(), NOW()),
(125, 120, 2, '查看举报',     'report.list',         '',            '',                 6, 1, NOW(), NOW()),
(126, 120, 2, '处理举报',     'report.handle',       '',            '',                 5, 1, NOW(), NOW()),
(127, 120, 2, '积分规则配置', 'points.rule',         '',            '',                 4, 1, NOW(), NOW()),
(128, 120, 2, '积分统计',     'points.stat',         '',            '',                 3, 1, NOW(), NOW()),
-- 开放平台（顶级菜单）
(140, 0,  1, '开放平台',     'open',                '/open-app',   'ApiOutlined',     50, 1, NOW(), NOW()),
(141, 140, 2, '查看应用',     'open.list',           '',            '',                10, 1, NOW(), NOW()),
(142, 140, 2, '新增应用',     'open.create',         '',            '',                 9, 1, NOW(), NOW()),
(143, 140, 2, '编辑应用',     'open.update',         '',            '',                 8, 1, NOW(), NOW()),
(144, 140, 2, '启用/禁用',    'open.status',         '',            '',                 7, 1, NOW(), NOW()),
(145, 140, 2, '轮换密钥',     'open.rotate',         '',            '',                 6, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `pid`   = VALUES(`pid`),
  `type`  = VALUES(`type`),
  `title` = VALUES(`title`),
  `name`  = VALUES(`name`),
  `path`  = VALUES(`path`),
  `icon`  = VALUES(`icon`),
  `sort`  = VALUES(`sort`),
  `update_time` = NOW();

-- =========================================================
-- 2. 用户组授权（只授具体权限点，不授菜单节点）
-- =========================================================
-- 内容运营（id=2）：评论与举报的处置，以及相关规则的配置
--
-- 注意：评论配置（124，含先审后发开关与敏感词库）与积分规则（127/128）
-- 必须跟着审核权限一起给运营。一开始曾把它们留给了超管，结果是
-- 「运营要审评论，却开不了先审后发、加不了敏感词」，业务上走不通 ——
-- 审核与审核所依据的规则，得由同一批人维护。
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',121') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('121', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',122') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('122', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',123') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('123', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',124') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('124', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',125') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('125', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',126') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('126', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',127') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('127', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',128') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('128', `rules`);

-- 用户管理相关：运营处理举报时必然要查用户、封禁账号、补偿积分，
-- 否则「能看到举报单，却查不到被举报人是谁」，同样走不通。
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',101') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('101', `rules`); -- 查看用户
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',111') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('111', `rules`); -- 新增用户
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',102') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('102', `rules`); -- 用户详情
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',103') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('103', `rules`); -- 编辑用户
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',104') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('104', `rules`); -- 启用/禁用（封禁违规账号）
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',106') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('106', `rules`); -- 调整积分
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',107') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('107', `rules`); -- 调整经验
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',108') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('108', `rules`); -- 查看等级规则（调经验时要知道门槛）
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',141') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('141', `rules`); -- 查看应用（与只读访客一致，列表为脱敏展示）

-- 刻意不给内容运营组的权限点：
--   105 重置密码        —— 直接接管账号，留给超管
--   109 / 110 等级增删  —— 等级体系是站点级配置，留给超管
--   142 – 145 应用增删改与密钥轮换 —— 涉及对外凭证，留给超管

-- 只读访客（id=3）：只开放「查看」类，无任何写权限
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',101') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('101', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',108') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('108', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',121') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('121', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',125') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('125', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',141') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('141', `rules`);

-- =========================================================
-- 3. 执行结果校验（规则应为 27 条 = 3 个菜单 + 24 个权限点）
-- =========================================================
SELECT
  (SELECT COUNT(*) FROM `zsk_auth_rule`
     WHERE `id` IN (100,101,102,103,104,105,106,107,108,109,110,111,
                    120,121,122,123,124,125,126,127,128,
                    140,141,142,143,144,145)) AS p3_rules,
  (SELECT `rules` FROM `zsk_auth_group` WHERE `id` = 2) AS group_operator,
  (SELECT `rules` FROM `zsk_auth_group` WHERE `id` = 3) AS group_viewer;

SELECT `id`, `pid`, `type`, `title`, `name`, `path`, `sort`, `status`
FROM `zsk_auth_rule`
WHERE `id` IN (100,101,102,103,104,105,106,107,108,109,110,111,
               120,121,122,123,124,125,126,127,128,
               140,141,142,143,144,145)
ORDER BY `id`;

-- =========================================================
-- 回滚（默认注释，确认后手工执行）
--  执行后需把用户组 rules 恢复为下方原值（执行本脚本前的值）
-- =========================================================
-- DELETE FROM `zsk_auth_rule`
--   WHERE `id` IN (100,101,102,103,104,105,106,107,108,109,110,111,
--                  120,121,122,123,124,125,126,127,128,
--                  140,141,142,143,144,145);
-- UPDATE `zsk_auth_group` SET `rules` = '10,11,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,37,38,45,281,282,291,292,293,294,451,452,453,454,455' WHERE `id` = 2;
-- UPDATE `zsk_auth_group` SET `rules` = '10,11,21,31,41,291,451'                                                                                     WHERE `id` = 3;
