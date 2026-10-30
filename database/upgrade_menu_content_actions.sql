-- =============================================================
--  把内容相关的操作权限点收拢到「内容列表」菜单下（2026.10.30）
--
--  现状：#21 – #27、#37、#38 这 9 个 type=2 权限点的 pid 直接等于 #20「内容管理」，
--        于是在「权限菜单」管理页里，它们与「内容列表 / 内容回收站 / 标签管理 /
--        分类管理」四个菜单平铺在同一层，看不出归属关系。
--
--  目标：
--
--    内容管理              （容器，无 path）
--        · 内容列表        （菜单 /content）
--              · 查看列表   content.list
--              · 新增内容   content.create
--              · 编辑内容   content.update
--              · 删除内容   content.delete
--              · 批量删除   content.batch-delete
--              · 发布/下架  content.status
--              · 置顶操作   content.top
--              · 内容导出   content.export
--              · 内容复制   content.copy
--        · 内容回收站
--        · 标签管理
--        · 分类管理
--
--  为什么可以只改 pid：
--    · 用户组授权存的是 auth_rule 的 id 集合，本脚本不改任何 id，因此授权配置一行不动
--    · Permission::keysByRuleIds() 自己递归展开子树，与 pid 归属无关 → 有效权限点不变
--    · Permission::filterMenuTree() 的判定是「菜单自身被授权 或 其后代任一权限点被授权」，
--      移入子节点后 #201 依旧满足条件，菜单不会消失
--    · 接口鉴权走 config/auth_route.php 的权限标识，与本表层级完全无关
--
--  依赖：必须在 upgrade_menu_content_list.sql 之后执行（#201 需先存在）
--
--  幂等：pid 已经是 201 时第二次执行影响 0 行
-- =============================================================

SET NAMES utf8mb4;

UPDATE `zsk_auth_rule`
SET `pid`         = 201,
    `update_time` = NOW()
WHERE `id` IN (21, 22, 23, 24, 25, 26, 27, 37, 38)
  AND `pid` = 20;

-- ---------------------------------------------------------
-- 关键配套：把菜单 #201 从各用户组的 rules 中剔除
--
-- 为什么必须做这一步：
--   Permission::keysByRuleIds() 会对 rules 里的每个 id 递归展开整棵子树，
--   「授予菜单 ID」等于「授予该菜单下全部权限点」。#201 原本是叶子，
--   上面一步给它挂了 9 个子节点后，rules 里含 201 的组会被一并放大。
--
--   实测：只读访客组（rules 含 201）的权限点由 6 个涨到 14 个，
--   多出 content.create / update / delete / batch-delete / status / top /
--   export / copy 八个写操作 —— 只读账号拿到了全部内容操作权。
--
-- 剔除后为什么菜单还在：
--   #201 自身 name = content.list，这些组都另行授权了 #21(content.list)，
--   filterMenuTree() 的 selfOwned 判定会保住「内容列表」菜单，不受影响。
--
-- 幂等：已剔除时第二次执行影响 0 行
-- ---------------------------------------------------------
UPDATE `zsk_auth_group`
SET `rules` = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', `rules`, ','), ',201,', ',')),
    `update_time` = NOW()
WHERE `rules` IS NOT NULL
  AND `rules` <> ''
  AND `rules` <> '*'
  AND CONCAT(',', `rules`, ',') LIKE '%,201,%';

-- ---------------------------------------------------------
-- 校验
-- ---------------------------------------------------------
-- 应为 0：仍留在内容管理容器下的权限点
SELECT COUNT(*) AS leftover_under_content_menu
FROM `zsk_auth_rule`
WHERE `pid` = 20 AND `type` = 2;

-- 应为 9：内容列表下的权限点
SELECT COUNT(*) AS actions_under_content_list
FROM `zsk_auth_rule`
WHERE `pid` = 201 AND `type` = 2;

-- 内容列表的直接子节点清单
SELECT `id`, `title`, `name`, `sort`
FROM `zsk_auth_rule`
WHERE `pid` = 201 AND `type` = 2
ORDER BY `sort` DESC, `id` ASC;

-- 应为 0：仍有用户组把菜单 #201 写进 rules（会放大权限）
SELECT COUNT(*) AS groups_still_holding_menu_201
FROM `zsk_auth_group`
WHERE `rules` IS NOT NULL
  AND `rules` <> ''
  AND `rules` <> '*'
  AND CONCAT(',', `rules`, ',') LIKE '%,201,%';
