-- =============================================================
--  第三阶段（M3.0）种子数据：用户等级标识 + 敏感词库
--  依赖：database/upgrade_front_v3.sql 已执行（两张表已存在）
--
--  特性：
--    · 全部用 ON DUPLICATE KEY UPDATE，可重复执行且不产生重复行
--    · 只更新配置字段，不动 status（运营手工停用过的等级/词条不会被重新启用）
--
--  执行顺序：必须在 upgrade_front_v3.sql 之后
--  回滚语句见文件末尾（默认注释，需手工执行）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. 用户等级标识（6 级）
--    等级只作为「用户成长到什么阶段」的标记，不参与任何权限判断 ——
--    收藏、评论、点赞对任何已登录用户开放，与等级无关。
--    min_exp 仅决定这个标记何时随经验变化，不是功能门槛。
--
--    这里原先写的是「Lv2 解锁收藏夹分组、评论点赞」「Lv3 评论免审核」
--    这类权益描述，但代码里从来没有按等级鉴权过：
--    评论审核只看 comment.audit_first 开关与敏感词命中。
--    文案凭空承诺权限，会同时误导运营和用户，已改为中性的标识说明。
--
--    经验门槛按「注册 → 连续签到 → 发评论 → 被采纳」的成长曲线设计，
--    首级 0 经验、末级 30000，保证新用户当天能升 1 级、长期用户有目标感。
--    icon 留空：等视觉资源确定后再补，避免先塞占位图再返工。
-- =========================================================
INSERT INTO `zsk_user_level`
  (`level`, `name`, `icon`, `min_exp`, `rights_desc`, `status`, `create_time`, `update_time`)
VALUES
  (1, '见习读者', '',    0, '新用户初始标识', 1, NOW(), NOW()),
  (2, '初级读者', '',  100, '入门成长标识', 1, NOW(), NOW()),
  (3, '中级读者', '',  500, '进阶成长标识', 1, NOW(), NOW()),
  (4, '高级读者', '', 2000, '活跃成长标识', 1, NOW(), NOW()),
  (5, '资深读者', '', 8000, '资深成长标识', 1, NOW(), NOW()),
  (6, '专家读者', '',30000, '最高等级标识', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `min_exp`     = VALUES(`min_exp`),
  `rights_desc` = VALUES(`rights_desc`),
  `update_time` = NOW();

-- =========================================================
-- 2. 敏感词库（基础词库）
--    范围说明：这里只放「广告 / 垃圾推广 / 引流」类词条 ——
--    这是评论审核最高频的场景，且词条本身不含违规内容。
--    涉政、色情、暴恐等类别由运营在后台按合规词库导入，不写进代码仓库。
--
--    处理级别：1 仅提醒  2 替换  3 拦截并转人工审核
-- =========================================================
INSERT INTO `zsk_sensitive_word`
  (`word`, `level`, `replacement`, `status`, `create_time`, `update_time`)
VALUES
  ('加微信',     2, '***', 1, NOW(), NOW()),
  ('加v信',      2, '***', 1, NOW(), NOW()),
  ('加微',       2, '***', 1, NOW(), NOW()),
  ('私聊',       1, '',    1, NOW(), NOW()),
  ('扫码',       2, '***', 1, NOW(), NOW()),
  ('刷单',       3, '',    1, NOW(), NOW()),
  ('刷信誉',     3, '',    1, NOW(), NOW()),
  ('代刷',       3, '',    1, NOW(), NOW()),
  ('兼职日结',   3, '',    1, NOW(), NOW()),
  ('日入过万',   3, '',    1, NOW(), NOW()),
  ('低价出售',   2, '***', 1, NOW(), NOW()),
  ('内部渠道',   2, '***', 1, NOW(), NOW()),
  ('包过',       2, '***', 1, NOW(), NOW()),
  ('点击链接',   1, '',    1, NOW(), NOW()),
  ('免费领取',   1, '',    1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `level`       = VALUES(`level`),
  `replacement` = VALUES(`replacement`),
  `update_time` = NOW();

-- =========================================================
-- 3. 执行结果校验（等级 6 条、词条 15 条）
-- =========================================================
SELECT
  (SELECT COUNT(*) FROM `zsk_user_level` WHERE `level` BETWEEN 1 AND 6) AS levels,
  (SELECT COUNT(*) FROM `zsk_sensitive_word`) AS sensitive_words;

SELECT `level`, `name`, `min_exp`, `status` FROM `zsk_user_level` ORDER BY `level`;
SELECT `level` AS handle_level, COUNT(*) AS cnt FROM `zsk_sensitive_word` GROUP BY `level` ORDER BY `level`;

-- =========================================================
-- 回滚（默认注释，确认后手工执行）
--  两张表当前只有种子数据，直接清空即可
-- =========================================================
-- DELETE FROM `zsk_sensitive_word`;
-- DELETE FROM `zsk_user_level`;
