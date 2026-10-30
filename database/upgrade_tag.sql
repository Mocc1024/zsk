-- =============================================================
--  标签数据迁移（M2.5）
--  目标：把 zsk_content.tags 的「逗号分隔字符串」迁移到
--        zsk_tag（标签表）+ zsk_content_tag（关联表）
--
--  特性：全部使用 NOT EXISTS / INSERT IGNORE，可重复执行，不丢数据
--  前提：database/upgrade_content_v2.sql 已执行（标签表与关联表存在）
--
--  说明：zsk_content.tags 字段保留为冗余，继续供列表搜索与展示使用；
--        关联表才是标签的唯一权威来源
-- =============================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. 先规整旧数据：把中文逗号统一成英文逗号
-- ---------------------------------------------------------
UPDATE `zsk_content`
SET `tags` = REPLACE(`tags`, '，', ',')
WHERE `tags` LIKE '%，%';

-- ---------------------------------------------------------
-- 2. 从逗号串中拆出标签名并写入标签表（重复执行不会重复插入）
--    最多支持单篇 10 个标签，超出部分需手工处理
-- ---------------------------------------------------------
INSERT INTO `zsk_tag` (`name`, `slug`, `count`, `sort`, `status`, `create_time`, `update_time`)
SELECT
    t.`name`,
    CONCAT('tag-', SUBSTRING(MD5(t.`name`), 1, 10)),
    0, 0, 1, NOW(), NOW()
FROM (
    SELECT DISTINCT
        TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.`tags`, ',', n.`i`), ',', -1)) AS `name`
    FROM `zsk_content` c
    CROSS JOIN (
        SELECT 1 AS `i` UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
        UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    ) n
    WHERE c.`delete_time` IS NULL
      AND c.`tags` IS NOT NULL
      AND c.`tags` <> ''
      AND SUBSTRING_INDEX(SUBSTRING_INDEX(c.`tags`, ',', n.`i`), ',', -1)
       <> SUBSTRING_INDEX(SUBSTRING_INDEX(c.`tags`, ',', n.`i` - 1), ',', -1)
) t
WHERE t.`name` <> ''
  AND NOT EXISTS (
      SELECT 1 FROM `zsk_tag` g WHERE g.`name` = t.`name` AND g.`delete_time` IS NULL
  );

-- ---------------------------------------------------------
-- 3. 建立内容与标签的关联（主键去重，重复执行安全）
-- ---------------------------------------------------------
INSERT IGNORE INTO `zsk_content_tag` (`content_id`, `tag_id`)
SELECT c.`id`, g.`id`
FROM `zsk_content` c
CROSS JOIN (
    SELECT 1 AS `i` UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
) n
JOIN `zsk_tag` g
    ON g.`name` = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.`tags`, ',', n.`i`), ',', -1))
WHERE c.`delete_time` IS NULL
  AND c.`tags` IS NOT NULL
  AND c.`tags` <> ''
  AND TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.`tags`, ',', n.`i`), ',', -1)) <> '';

-- ---------------------------------------------------------
-- 4. 重算标签引用数
-- ---------------------------------------------------------
UPDATE `zsk_tag` g
SET g.`count` = (
    SELECT COUNT(*)
    FROM `zsk_content_tag` ct
    JOIN `zsk_content` c ON c.`id` = ct.`content_id`
    WHERE ct.`tag_id` = g.`id`
      AND c.`delete_time` IS NULL
)
WHERE g.`delete_time` IS NULL;

-- ---------------------------------------------------------
-- 5. 执行结果校验
-- ---------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM `zsk_tag` WHERE `delete_time` IS NULL)     AS tag_total,
  (SELECT COUNT(*) FROM `zsk_content_tag`)                          AS relation_total,
  (SELECT COUNT(*) FROM `zsk_content`
     WHERE `delete_time` IS NULL AND `tags` IS NOT NULL AND `tags` <> '') AS content_with_tags;

-- ---------------------------------------------------------
-- 回滚（默认注释，需手工执行）
-- ---------------------------------------------------------
-- UPDATE `zsk_tag` SET `count` = 0;
-- DELETE FROM `zsk_content_tag`;
-- DELETE FROM `zsk_tag`;
