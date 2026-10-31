-- =============================================================
--  修复：zsk_user.mobile 唯一索引冲突
--  发现时间：M3.3（2026-10-31），由 scripts/test_user_flow.php 探针暴露
--
--  问题：
--    upgrade_front_v3.sql 首版把 mobile 建成了 NOT NULL DEFAULT ''，
--    而该列上有唯一索引 uk_mobile。MySQL 的唯一索引允许存在多个 NULL，
--    但不允许出现两个相同的空串 —— 结果是第二个没填手机号的用户
--    注册就会报 Duplicate entry '' for key 'uk_mobile'。
--    第一个用户能注册成功，从第二个开始全部失败。
--
--  修复：改为 DEFAULT NULL。没填手机号的用户该列为 NULL，互不冲突；
--        填了的用户仍然受唯一约束保护。
--
--  特性：幂等（先判 IS_NULLABLE，已修复则跳过）
-- =============================================================

SET NAMES utf8mb4;

-- 先把历史空串刷成 NULL，否则改列时会被唯一索引拦下
UPDATE `zsk_user` SET `mobile` = NULL WHERE `mobile` = '';

SET @nullable := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_user'
      AND COLUMN_NAME = 'mobile'
      AND IS_NULLABLE = 'YES'
);
SET @sql := IF(@nullable = 0,
    'ALTER TABLE `zsk_user` MODIFY COLUMN `mobile` varchar(20) DEFAULT NULL COMMENT ''手机号（通道后置，字段先建）''',
    'SELECT ''字段 zsk_user.mobile 已可空，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 校验：应为 YES
-- =========================================================
SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'zsk_user'
  AND COLUMN_NAME = 'mobile';
