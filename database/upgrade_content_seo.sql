-- =============================================================
--  第三阶段（M3.0）内容表扩展：SEO 字段与互动冗余计数
--  适用对象：已完成第二阶段、库中存在 zsk_content 的数据库
--
--  特性：
--    · 字段新增使用 information_schema 判重，可重复执行
--    · 全部带默认值，不影响既有数据
--
--  对应功能项：
--    seo_title / seo_keywords / seo_description  → 3.5 SEO 与站点配置
--    favorite_count / comment_count              → 4.5 内容互动计数（冗余字段，避免列表页实时 count）
--
--  执行顺序：与 upgrade_front_v3.sql 无依赖，先后均可
--  搜索用的全文索引不在此文件，见 database/upgrade_content_search.sql（M3.2 执行）
--  回滚语句见文件末尾（默认注释，需手工执行）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. SEO 三字段（3.5）
-- =========================================================
SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'seo_title'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `seo_title` varchar(200) NOT NULL DEFAULT '''' COMMENT ''SEO标题，空则回退用 title'' AFTER `summary`',
    'SELECT ''字段 zsk_content.seo_title 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'seo_keywords'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `seo_keywords` varchar(255) NOT NULL DEFAULT '''' COMMENT ''SEO关键词，逗号分隔'' AFTER `seo_title`',
    'SELECT ''字段 zsk_content.seo_keywords 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'seo_description'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `seo_description` varchar(500) NOT NULL DEFAULT '''' COMMENT ''SEO描述，空则回退用 summary'' AFTER `seo_keywords`',
    'SELECT ''字段 zsk_content.seo_description 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 2. 互动冗余计数（4.5）
-- 冗余字段的更新纪律：
--   写操作在主表事务内同步增减，异步校正脚本（php think stat:correct）兜底。
--   不要直接在列表页 COUNT(*)，数据量上来会拖垮列表接口。
-- =========================================================
SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'favorite_count'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `favorite_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT ''收藏数（冗余）'' AFTER `views`',
    'SELECT ''字段 zsk_content.favorite_count 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'comment_count'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `comment_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT ''评论数（冗余，仅统计已通过）'' AFTER `favorite_count`',
    'SELECT ''字段 zsk_content.comment_count 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 3. 执行结果校验（应为 5）
-- =========================================================
SELECT COUNT(*) AS created_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'zsk_content'
  AND COLUMN_NAME IN ('seo_title','seo_keywords','seo_description','favorite_count','comment_count');

-- =========================================================
-- 回滚（默认注释，确认后手工执行）
-- =========================================================
-- ALTER TABLE `zsk_content` DROP COLUMN `comment_count`;
-- ALTER TABLE `zsk_content` DROP COLUMN `favorite_count`;
-- ALTER TABLE `zsk_content` DROP COLUMN `seo_description`;
-- ALTER TABLE `zsk_content` DROP COLUMN `seo_keywords`;
-- ALTER TABLE `zsk_content` DROP COLUMN `seo_title`;
