-- =============================================================
--  恢复「内容列表」菜单入口（M2.7 修复）
--
--  背景：zsk_auth_rule 里 #20「内容管理」原先自身就是列表页菜单（path=/content）。
--        M2.3 加入 #28「内容回收站」、M2.5 加入 #29「标签管理」后都挂在 #20 之下，
--        于是 #20 由「可点击菜单」变成了「父级容器」——展开后只剩回收站和标签管理，
--        列表页再也找不到独立入口。
--
--  做法：
--    1. #20 改为纯容器，path 置空（与「系统管理」#50 的写法保持一致）；
--    2. 新增 #201「内容列表」作为二级菜单，path=/content。
--
--  为什么 name 用 content.list：菜单树对非超管用户按「自身是否被授权」过滤，
--    而 #201 没有子节点，必须自带一个被授权的权限标识才能在内容运营 / 只读访客
--    组的菜单里出现。content.list 正是列表页的读权限，语义一致且表上无唯一约束。
--
--  幂等：INSERT ... ON DUPLICATE KEY UPDATE，可重复执行
-- =============================================================

SET NAMES utf8mb4;

-- 1. #20 内容管理改为容器节点（不再承载 /content 自身的跳转）
UPDATE `zsk_auth_rule`
SET `path`       = '',
    `icon`       = 'FileTextOutlined',
    `update_time` = NOW()
WHERE `id` = 20;

-- 2. 补回列表菜单（放在回收站 sort=30 之上，确保排第一）
INSERT INTO `zsk_auth_rule`
    (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`)
VALUES
    (201, 20, 1, '内容列表', 'content.list', '/content', 'FileTextOutlined', 31, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `pid`         = VALUES(`pid`),
    `type`        = VALUES(`type`),
    `title`       = VALUES(`title`),
    `name`        = VALUES(`name`),
    `path`        = VALUES(`path`),
    `icon`        = VALUES(`icon`),
    `sort`        = VALUES(`sort`),
    `status`      = VALUES(`status`),
    `update_time` = NOW();
