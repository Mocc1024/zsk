-- =============================================================
--  权限功能升级脚本
--  适用对象：已经安装过旧版 zsk.sql、库里已有数据的数据库
--
--  注意：database/zsk.sql 会先 DROP 再 CREATE，会清空所有数据。
--        已有数据的库请执行本文件，它只做「加字段」，不动原有数据。
-- =============================================================

SET NAMES utf8mb4;

-- 1. admin 表新增 permissions 字段（已存在则先删除再加，可重复执行）
SET @exist := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_admin'
      AND COLUMN_NAME = 'permissions'
);

SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_admin` ADD COLUMN `permissions` TEXT NULL COMMENT ''权限点集合 JSON数组 如["content.list"] 超管为NULL'' AFTER `role`',
    'SELECT ''字段 zsk_admin.permissions 已存在，跳过'''
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. 超级管理员权限字段置空（超管永远拥有全部权限，不依赖该字段）
UPDATE `zsk_admin` SET `permissions` = NULL WHERE `role` = 'admin';

-- 3. 给现有编辑账号补一套默认权限（原来是 NULL 的才补，避免覆盖已有配置）
UPDATE `zsk_admin`
SET `permissions` = '["dashboard.view","content.list","content.create","content.update","category.list"]'
WHERE `role` = 'editor'
  AND (`permissions` IS NULL OR `permissions` = '');

-- 4. 查看结果
SELECT id, username, role, permissions FROM `zsk_admin` WHERE `delete_time` IS NULL;
