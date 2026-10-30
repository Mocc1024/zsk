-- =============================================================
--  下线「内容版本」（原 M2.4 的一部分）
--
--  下线理由：
--    版本记录（保存快照 / 与当前对比 / 回滚）实际无人使用，
--    且是唯一会整篇覆盖正文的写入口，维护与风险都不划算。
--
--  本脚本处理两部分：
--    1. 删除版本表 zsk_content_version（含全部历史快照）
--    2. 删除 zsk_content.version_count 字段
--
--  无需回收权限点：版本查看 / 回滚本来就并入 content.list / content.update，
--  没有独立的 content.version 权限点，zsk_auth_rule 与 zsk_auth_group.rules 不受影响。
--
--  幂等：DROP TABLE IF EXISTS、删列前判断 information_schema，均可重复执行
--
--  不可逆：第 1 步会丢弃全部历史版本快照，无法找回。
--          代码侧已经不再写入该表（ContentVersionService 已删除），
--          仅清理残留数据；如果不确定，可以先只执行第 2 步。
--
--  执行前建议先看看有多少数据会被删掉：
--          SELECT COUNT(*) FROM zsk_content_version;
--          SELECT COUNT(*) FROM zsk_content WHERE version_count > 0;
-- =============================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. 删除版本表
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `zsk_content_version`;

-- ---------------------------------------------------------
-- 2. 删除内容表上的版本计数
-- ---------------------------------------------------------
SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'version_count'
);
SET @sql := IF(@exist > 0,
    'ALTER TABLE `zsk_content` DROP COLUMN `version_count`',
    'SELECT ''字段 zsk_content.version_count 不存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------
-- 校验：下面两条都应为 0
-- ---------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content_version') AS leftover_table,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'version_count') AS leftover_column;
