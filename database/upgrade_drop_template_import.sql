-- =============================================================
--  下线「内容模板」与「内容导入」（M2.7）
--
--  下线理由：
--    模板 = 给已有内容打 is_template 标记，使用时整篇复制成草稿，
--           与「复制为草稿」几乎等价，入口收益不足以抵消维护成本；
--    导入 = CSV / Markdown / ZIP 批量建库，实际无人使用，
--           且是唯一允许绕过编辑器写入正文的入口（XSS 面更大）。
--
--  本脚本处理三部分：
--    1. 删除权限点：#36 content.import、#39 content.template
--    2. 把这两个 id 从各用户组的 rules 里剔除（保留其余授权）
--    3. 删除 zsk_content.is_template 字段
--
--  幂等：DELETE 指定 id、REPLACE 剔除、删列前判断 information_schema，均可重复执行
--
--  不可逆：第 3 步会丢弃模板标记。执行前建议先确认：
--          SELECT is_template, COUNT(*) FROM zsk_content GROUP BY is_template;
--          （实际执行时全部为 0，无有效数据丢失）
-- =============================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. 删除权限点规则
-- ---------------------------------------------------------
DELETE FROM `zsk_auth_rule`
WHERE `id` IN (36, 39)
  AND `name` IN ('content.import', 'content.template');

-- ---------------------------------------------------------
-- 2. 清理用户组授权里的对应 id
--
-- 手法：先在 rules 两端补逗号，替换 ",36," → "," 后去掉逗号。
--       加逗号是为了精确匹配整段 id，避免误伤 361、139 这类数字。
--       rules = '*' 的超级管理员组不受影响（由 WHERE 排除）。
-- ---------------------------------------------------------
UPDATE `zsk_auth_group`
SET `rules` = TRIM(BOTH ',' FROM
        REPLACE(
            REPLACE(CONCAT(',', `rules`, ','), ',36,', ','),
            ',39,', ','
        )
    ),
    `update_time` = NOW()
WHERE `rules` IS NOT NULL
  AND `rules` <> ''
  AND `rules` <> '*'
  AND (`rules` LIKE '%,36,%' OR `rules` LIKE '%,39,%'
       OR `rules` LIKE '36,%' OR `rules` LIKE '39,%');

-- ---------------------------------------------------------
-- 3. 删除模板标记字段
-- ---------------------------------------------------------
SET @exist := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'is_template'
);
SET @sql := IF(@exist > 0,
    'ALTER TABLE `zsk_content` DROP COLUMN `is_template`',
    'SELECT ''字段 zsk_content.is_template 不存在，跳过'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------
-- 校验：下面两条都应为 0
-- ---------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM `zsk_auth_rule`
    WHERE `id` IN (36, 39)
       OR `name` IN ('content.import', 'content.template')) AS leftover_rules,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'zsk_content'
      AND COLUMN_NAME = 'is_template') AS leftover_column;
