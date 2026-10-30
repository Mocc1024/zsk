-- =============================================================
--  第二阶段（M2.0）权限点升级：新增菜单与权限点
--  依赖：database/auth_rbac.sql 已执行（权限体系已存在）
--
--  特性：
--    · 按固定 ID 写入，ON DUPLICATE KEY UPDATE 保证可重复执行
--    · 不覆盖 status 字段（管理员手工启停不会被重置）
--    · 用户组授权用 FIND_IN_SET 判重，重复执行不会追加重复 ID
--    · rules='*' 的超级管理员组自动跳过，不受影响
--
--  说明：授予「菜单 ID」即自动展开其下全部权限点（由 Permission::keysByRuleIds 递归展开）
--  回滚语句见文件末尾（默认注释，需手工执行）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. 新增菜单与权限点
-- =========================================================
INSERT INTO `zsk_auth_rule` (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`) VALUES
-- 素材库（顶级菜单）
(45,  0,  1, '素材库',       'attachment',            '/attachment',    'PictureOutlined', 75, 1, NOW(), NOW()),
(451, 45, 2, '查看素材',     'attachment.list',       '',               '',                10, 1, NOW(), NOW()),
(452, 45, 2, '上传文件',     'attachment.upload',     '',               '',                 9, 1, NOW(), NOW()),
(453, 45, 2, '编辑素材',     'attachment.update',     '',               '',                 8, 1, NOW(), NOW()),
(454, 45, 2, '删除素材',     'attachment.delete',     '',               '',                 7, 1, NOW(), NOW()),
-- 内容管理 → 回收站
(28,  20, 1, '内容回收站',   'trash',                 '/content/trash', 'DeleteOutlined',   30, 1, NOW(), NOW()),
(281, 28, 2, '恢复内容',     'content.restore',       '',               '',                10, 1, NOW(), NOW()),
(282, 28, 2, '彻底删除',     'content.force-delete',  '',               '',                 9, 1, NOW(), NOW()),
-- 内容管理 → 标签管理
(29,  20, 1, '标签管理',     'tag',                   '/tag',           'TagsOutlined',     29, 1, NOW(), NOW()),
(291, 29, 2, '查看标签',     'tag.list',              '',               '',                10, 1, NOW(), NOW()),
(292, 29, 2, '新增标签',     'tag.create',            '',               '',                 9, 1, NOW(), NOW()),
(293, 29, 2, '编辑标签',     'tag.update',            '',               '',                 8, 1, NOW(), NOW()),
(294, 29, 2, '删除标签',     'tag.delete',            '',               '',                 7, 1, NOW(), NOW()),
-- 内容管理 → 导出 / 复制
(37,  201, 2, '内容导出',    'content.export',        '',               '',                 2, 1, NOW(), NOW()),
(38,  201, 2, '内容复制',    'content.copy',          '',               '',                 1, 1, NOW(), NOW())
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
-- 2. 用户组授权（授予菜单 ID 即展开其下权限点）
-- =========================================================
-- 内容运营（id=2）：素材库、回收站、标签管理 + 导出/复制
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',28') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('28', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',29') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('29', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',37') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('37', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',38') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('38', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',45') WHERE `id` = 2 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('45', `rules`);

-- 只读访客（id=3）：仅开放只读查看，不给任何写权限
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',291') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('291', `rules`);
UPDATE `zsk_auth_group` SET `rules` = CONCAT(`rules`, ',451') WHERE `id` = 3 AND `rules` IS NOT NULL AND `rules` <> '' AND `rules` <> '*' AND NOT FIND_IN_SET('451', `rules`);

-- =========================================================
-- 3. 执行结果校验
-- =========================================================
SELECT
  (SELECT COUNT(*) FROM `zsk_auth_rule` WHERE `id` IN (45,451,452,453,454,28,281,282,29,291,292,293,294,37,38)) AS p2_rules,
  (SELECT `rules` FROM `zsk_auth_group` WHERE `id` = 2) AS group_operator,
  (SELECT `rules` FROM `zsk_auth_group` WHERE `id` = 3) AS group_viewer;

SELECT `id`, `pid`, `type`, `title`, `name`, `path`, `sort`, `status`
FROM `zsk_auth_rule`
WHERE `id` IN (45,451,452,453,454,28,281,282,29,291,292,293,294,37,38)
ORDER BY `id`;

-- =========================================================
-- 回滚（默认注释，确认后手工执行）
--  执行后需把用户组 rules 恢复为下方原值
-- =========================================================
-- DELETE FROM `zsk_auth_rule` WHERE `id` IN (45,451,452,453,454,28,281,282,29,291,292,293,294,37,38);
-- UPDATE `zsk_auth_group` SET `rules` = '11,21,22,23,24,25,26,27,31,32,33,34,35' WHERE `id` = 2;
-- UPDATE `zsk_auth_group` SET `rules` = '11,21,31,41'                             WHERE `id` = 3;
