-- =============================================================
--  素材分组（M2.1 完善）
--  分组此前只是 zsk_attachment.folder 里的一个字符串：
--    无法新建空分组、无法重命名、无法删除、也没有数量统计。
--  这里新增独立的分组表，并把已有素材用过的分组回填进去。
--  可重复执行（CREATE IF NOT EXISTS + INSERT IGNORE）
-- =============================================================

SET NAMES utf8mb4;

-- ---------------- 分组表 ----------------
CREATE TABLE IF NOT EXISTS `zsk_attachment_folder` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`        varchar(30)  NOT NULL DEFAULT '' COMMENT '分组名称，唯一',
  `sort`        int(11)      NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材分组表';

-- ---------------- 内置分组 ----------------
INSERT IGNORE INTO `zsk_attachment_folder` (`name`, `sort`, `create_time`, `update_time`) VALUES
('default', 100, NOW(), NOW()),
('cover',    90, NOW(), NOW()),
('editor',   80, NOW(), NOW());

-- ---------------- 回填：已有素材中使用过的分组 ----------------
INSERT IGNORE INTO `zsk_attachment_folder` (`name`, `sort`, `create_time`, `update_time`)
SELECT DISTINCT `folder`, 0, NOW(), NOW()
FROM `zsk_attachment`
WHERE `folder` <> '' AND `delete_time` IS NULL;

-- ---------------- 权限点：素材分组管理（挂在「素材库」菜单 id=45 下）----------------
INSERT INTO `zsk_auth_rule`
  (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`)
VALUES
  (455, 45, 2, '分组管理', 'attachment.folder', '', '', 6, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `update_time` = NOW();

-- 「内容运营」组（id=2）追加该权限点，超级管理员组 rules='*' 无需处理
UPDATE `zsk_auth_group`
SET `rules` = CONCAT(`rules`, ',455')
WHERE `id` = 2
  AND `rules` IS NOT NULL
  AND `rules` <> '*'
  AND NOT FIND_IN_SET('455', `rules`);

-- ---------------- 校验输出 ----------------
SELECT
  (SELECT COUNT(*) FROM `zsk_attachment_folder` WHERE `delete_time` IS NULL) AS folder_count,
  (SELECT COUNT(*) FROM `zsk_auth_rule` WHERE `name` = 'attachment.folder') AS rule_exists;

-- ---------------- 回滚（默认注释，确认后手工执行）----------------
-- 注意：回滚后素材的 folder 字段值保持不变（仍是原分组名），
--       素材本身不受影响，仅丢失分组表与其数量统计。
-- DROP TABLE IF EXISTS `zsk_attachment_folder`;
-- UPDATE `zsk_auth_group`
--   SET `rules` = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', `rules`, ','), ',455,', ','))
--   WHERE FIND_IN_SET('455', `rules`);
-- DELETE FROM `zsk_auth_rule` WHERE `id` = 455;
