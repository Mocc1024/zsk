-- ============================================================
--  统一权限来源：移除管理员表上的 role / permissions 字段
--  权限改由 zsk_auth_group（用户组）的 rules 字段唯一决定
--  可重复执行（幂等）
-- ============================================================

-- 备份原表，便于回滚
DROP TABLE IF EXISTS `zsk_admin_bak_before_rbac`;
CREATE TABLE `zsk_admin_bak_before_rbac` AS SELECT * FROM `zsk_admin`;

SET @dbname = DATABASE();

-- 删除 role 字段（幂等）
SET @s1 = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'zsk_admin' AND COLUMN_NAME = 'role') > 0,
  'ALTER TABLE `zsk_admin` DROP COLUMN `role`',
  "SELECT 'role column already dropped' AS info"
));
PREPARE st1 FROM @s1;
EXECUTE st1;
DEALLOCATE PREPARE st1;

-- 删除 permissions 字段（幂等）
SET @s2 = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'zsk_admin' AND COLUMN_NAME = 'permissions') > 0,
  'ALTER TABLE `zsk_admin` DROP COLUMN `permissions`',
  "SELECT 'permissions column already dropped' AS info"
));
PREPARE st2 FROM @s2;
EXECUTE st2;
DEALLOCATE PREPARE st2;

-- 兜底：确保内置账号归属正确的用户组（组1 rules='*' 即超管）
UPDATE `zsk_admin` SET `group_id` = 1 WHERE `id` = 1;
UPDATE `zsk_admin` SET `group_id` = 2 WHERE `id` = 2;
INSERT IGNORE INTO `zsk_auth_group_access` (`uid`, `group_id`) VALUES (1, 1), (2, 2);
