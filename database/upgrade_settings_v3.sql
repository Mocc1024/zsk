-- =============================================================
--  第三阶段（M3.5 / M3.6 前置）站点配置表
--
--  为什么补这张表：
--    3.13 要求评论「开启/关闭 + 先审后发」后台可配，
--    3.15 要求积分规则可配。第二阶段的 14 张表里没有配置存储，
--    若写死在 config/*.php，运营改一次开关就要改代码发一次版。
--
--  形态：key-value + group。只存「运营要改的开关与阈值」，
--        不存技术参数（那些仍在 config/*.php）。
--
--  特性：CREATE TABLE IF NOT EXISTS + 种子数据 ON DUPLICATE KEY，可重复执行
-- =============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `zsk_setting` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group`       varchar(30)  NOT NULL DEFAULT 'site' COMMENT '分组 comment / points / site',
  `key`         varchar(50)  NOT NULL DEFAULT '' COMMENT '配置键',
  `value`       varchar(500) NOT NULL DEFAULT '' COMMENT '配置值（统一按字符串存，取用时转型）',
  `name`        varchar(60)  NOT NULL DEFAULT '' COMMENT '显示名，后台表单用',
  `remark`      varchar(255) NOT NULL DEFAULT '' COMMENT '说明',
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key` (`group`, `key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点配置表';

-- =========================================================
-- 种子：评论与积分的默认配置
-- =========================================================
INSERT INTO `zsk_setting` (`group`, `key`, `value`, `name`, `remark`, `update_time`) VALUES
  ('comment', 'enabled',      '1', '开启评论',       '关闭后前台不展示评论区且禁止提交', NOW()),
  ('comment', 'audit_first',  '0', '先审后发',       '开启后评论默认待审，审核通过才对外可见', NOW()),
  ('comment', 'min_length',   '2', '评论最小长度',   '短于此长度直接拒绝', NOW()),
  ('comment', 'max_length',   '500', '评论最大长度', '超出直接拒绝', NOW()),
  ('points',  'signin_base',  '5',  '签到基础积分',  '每日首次签到赠送', NOW()),
  ('points',  'signin_bonus', '10', '连续签到奖励',  '连续签到 7 天的额外奖励', NOW()),
  ('points',  'signin_exp',   '5',  '签到获得经验',  '每日首次签到赠送', NOW()),
  ('points',  'comment_gain', '2',  '评论获得积分',  '每发表一条通过审核的评论', NOW()),
  ('points',  'comment_daily_limit', '10', '评论每日积分上限', '防止刷评论刷积分', NOW()),
  ('points',  'exp_per_comment', '5', '评论获得经验', '每发表一条通过审核的评论', NOW())
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `remark`      = VALUES(`remark`),
  `update_time` = NOW();

-- =========================================================
-- 校验（应为 9 条）
-- =========================================================
SELECT `group`, COUNT(*) AS cnt FROM `zsk_setting` GROUP BY `group`;
