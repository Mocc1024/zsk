-- =============================================================
--  第二阶段（M2.0）内容能力升级：新增表与字段
--  适用对象：已完成第一阶段、库中存在 zsk_content 数据的数据库
--
--  特性：
--    · 全部使用 CREATE TABLE IF NOT EXISTS，可重复执行
--    · 字段新增使用 information_schema 判重，可重复执行
--    · 不删除、不修改任何既有数据
--
--  执行顺序：必须在 upgrade_permission_p2.sql 之前或之后均可，无依赖
--  回滚语句见文件末尾（默认注释，需手工执行）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. 附件素材表
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_attachment` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `type`        varchar(20)  NOT NULL DEFAULT 'image' COMMENT '类型：image 图片 / video 视频 / file 附件',
  `folder`      varchar(30)  NOT NULL DEFAULT '' COMMENT '分组标识，如 default / cover / editor',
  `name`        varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `path`        varchar(255) NOT NULL DEFAULT '' COMMENT '相对存储路径',
  `url`         varchar(255) NOT NULL DEFAULT '' COMMENT '访问 URL',
  `mime`        varchar(100) NOT NULL DEFAULT '' COMMENT 'MIME 类型',
  `ext`         varchar(10)  NOT NULL DEFAULT '' COMMENT '扩展名',
  `size`        int(10) unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `width`       smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '图片宽度',
  `height`      smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '图片高度',
  `alt`         varchar(255) NOT NULL DEFAULT '' COMMENT '替代文本',
  `ref_count`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '被引用次数，>0 时禁止删除',
  `admin_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '上传者管理员ID',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_folder` (`folder`),
  KEY `idx_create` (`create_time`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='附件素材表';

-- =========================================================
-- 2. 内容版本表
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_content_version` (
  `id`             int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `content_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '内容ID',
  `version`        smallint(5) unsigned NOT NULL DEFAULT 1 COMMENT '版本号，从 1 递增',
  `title`          varchar(200) NOT NULL DEFAULT '' COMMENT '标题快照',
  `category_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '分类快照',
  `cover`          varchar(255) NOT NULL DEFAULT '' COMMENT '封面快照',
  `tags`           varchar(255) NOT NULL DEFAULT '' COMMENT '标签快照（逗号分隔）',
  `summary`        varchar(500) NOT NULL DEFAULT '' COMMENT '摘要快照',
  `content`        mediumtext NULL COMMENT '正文快照',
  `status`         tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态快照',
  `is_top`         tinyint(1) NOT NULL DEFAULT 0 COMMENT '置顶快照',
  `changed_fields` varchar(255) NOT NULL DEFAULT '' COMMENT '本次变更字段，逗号分隔',
  `source`         varchar(20) NOT NULL DEFAULT 'manual' COMMENT '来源：manual 手工 / auto 自动保存 / restore 回滚',
  `admin_id`       int(10) unsigned NOT NULL DEFAULT 0 COMMENT '操作者管理员ID',
  `create_time`    datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_content_version` (`content_id`, `version`),
  KEY `idx_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容版本表';

-- =========================================================
-- 3. 标签表
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_tag` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`        varchar(50) NOT NULL DEFAULT '' COMMENT '标签名',
  `slug`        varchar(60) NOT NULL DEFAULT '' COMMENT '别名，用于 URL',
  `count`       int(10) unsigned NOT NULL DEFAULT 0 COMMENT '引用内容数',
  `sort`        int(11) NOT NULL DEFAULT 0 COMMENT '排序，越大越前',
  `status`      tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1启用 0禁用',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_sort` (`sort`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='标签表';

-- =========================================================
-- 4. 内容标签关联表
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_content_tag` (
  `content_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '内容ID',
  `tag_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '标签ID',
  PRIMARY KEY (`content_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容标签关联表';

-- =========================================================
-- 5. 附件引用关系表（用于引用次数统计与删除保护）
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_attachment_ref` (
  `content_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '内容ID',
  `attachment_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '附件ID',
  PRIMARY KEY (`content_id`, `attachment_id`),
  KEY `idx_attachment` (`attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容附件引用关系表';

-- =========================================================
-- 6. zsk_content 新增字段（幂等）
-- =========================================================
-- 注：曾在此新增 is_template，模板功能已下线，改由
--     database/upgrade_drop_template_import.sql 处理已有库的清理

SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'attachment_count'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `attachment_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT ''引用附件数'' AFTER `is_top`',
    'SELECT ''字段 zsk_content.attachment_count 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'version_count'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD COLUMN `version_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT ''版本数'' AFTER `attachment_count`',
    'SELECT ''字段 zsk_content.version_count 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 7. 执行结果校验
-- =========================================================
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
       ('zsk_attachment','zsk_attachment_ref','zsk_content_version','zsk_tag','zsk_content_tag')) AS created_tables,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zsk_content'
       AND COLUMN_NAME IN ('attachment_count','version_count')) AS created_columns;

-- =========================================================
-- 回滚（默认注释，确认后手工执行；会丢失第二阶段数据）
-- =========================================================
-- ALTER TABLE `zsk_content` DROP COLUMN `version_count`;
-- ALTER TABLE `zsk_content` DROP COLUMN `attachment_count`;
-- DROP TABLE IF EXISTS `zsk_attachment_ref`;
-- DROP TABLE IF EXISTS `zsk_content_tag`;
-- DROP TABLE IF EXISTS `zsk_tag`;
-- DROP TABLE IF EXISTS `zsk_content_version`;
-- DROP TABLE IF EXISTS `zsk_attachment`;
