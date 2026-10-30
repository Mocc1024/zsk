-- =============================================================
--  数据模型清理（M2.6）：删除 zsk_admin.permissions 字段
--
--  背景：该字段自第一阶段起就未参与任何逻辑。
--        管理员实际权限是由 Permission::responseFor() 从
--        所属用户组（zsk_auth_group.rules）实时派生的，
--        「复用数据保存到」的设想从未落实，字段纯属冗余。
--
--  特性：information_schema 判重，可重复执行
-- =============================================================

SET NAMES utf8mb4;

SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_admin'
      AND COLUMN_NAME = 'permissions'
);
SET @sql := IF(@exist > 0,
    'ALTER TABLE `zsk_admin` DROP COLUMN `permissions`',
    'SELECT ''字段 zsk_admin.permissions 已不存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'zsk_admin'
       AND COLUMN_NAME = 'permissions') AS permissions_column_left;

-- ---------------------------------------------------------
-- 回滚（默认注释，需手工执行；重建为可空字符串字段）
-- ---------------------------------------------------------
-- ALTER TABLE `zsk_admin` ADD COLUMN `permissions` text NULL COMMENT '权限（冗余字段，已废弃）' AFTER `group_id`;
