-- =============================================================
--  第三阶段（M3.2 前置）内容搜索：中文全文索引
--  适用对象：MySQL 5.7.6+ 且 ngram 分词可用
--
--  环境实测（2026-10-31）：MySQL 5.7.26，ngram_token_size = 2，可直接使用
--
--  特性：
--    · 用 information_schema.STATISTICS 判重，可重复执行
--    · 只加索引，不改动任何数据
--
--  为什么用 ngram 而不是默认解析器：
--    默认 InnoDB 全文解析器按「空格/标点」切词，中文整句会被当成一整个词，
--    搜不出结果。ngram 按 2 字滑窗切词（由 ngram_token_size 决定），适合中文。
--    实测 ngram_token_size = 2，无需改配置（该变量是启动项，会话内不可改）。
--
--  已知限制（M3.2 实现时要处理）：
--    1. 单字关键词（如「云」）不会命中 —— 最小 token 为 2 字，前端需提示或回退 LIKE
--    2. 正文含 HTML 标签，索引里也会带上标签文本，需在应用层对结果做二次过滤
--    3. innodb_ft_min_token_size = 3 只作用于默认解析器，ngram 不受其影响
--
--  执行时机：M3.2 搜索落地前执行即可，提前执行无副作用
--  回滚语句见文件末尾（默认注释，需手工执行）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. 创建全文索引（幂等）
-- =========================================================
SET @exist := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND INDEX_NAME = 'ft_search'
);
SET @sql := IF(@exist = 0,
    'ALTER TABLE `zsk_content` ADD FULLTEXT INDEX `ft_search` (`title`, `summary`, `content`) WITH PARSER ngram',
    'SELECT ''索引 zsk_content.ft_search 已存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 2. 执行结果校验（应为 1）
-- =========================================================
SELECT COUNT(*) AS created_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'zsk_content'
  AND INDEX_NAME = 'ft_search';

-- =========================================================
-- 3. 冒烟验证（库内有已发布内容时可手工执行，确认能搜到）
-- =========================================================
-- SELECT id, title, MATCH(`title`, `summary`, `content`) AGAINST ('数字化转型' IN BOOLEAN MODE) AS score
-- FROM `zsk_content`
-- WHERE `delete_time` IS NULL AND `status` = 1
--   AND MATCH(`title`, `summary`, `content`) AGAINST ('数字化转型' IN BOOLEAN MODE)
-- ORDER BY score DESC LIMIT 5;

-- =========================================================
-- 回滚（默认注释，确认后手工执行）
-- =========================================================
-- ALTER TABLE `zsk_content` DROP INDEX `ft_search`;
