-- 后台侧边栏增加「个人中心」菜单
--
-- 与安全设置同理：菜单是否对某用户可见，取决于它下面有没有被授权的权限点。
-- Permission::filterMenuTree() 只看 type=2 权限点的 name，菜单自身的 name
-- （这里是 'profile'）不参与判定，所以必须同时建子权限点并写进用户组 rules。
--
-- 执行：php scripts/run_sql.php database/upgrade_profile_menu.sql

-- 菜单节点：挂在「系统管理」(id=50) 下，sort=55 排在安全设置(56)之后
INSERT IGNORE INTO `zsk_auth_rule`
  (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`)
VALUES
  (55, 50, 1, '个人中心', 'profile', '/profile', 'UserOutlined', 55, 1, NOW(), NOW());

-- 子权限点：决定菜单是否出现在侧边栏
INSERT IGNORE INTO `zsk_auth_rule`
  (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`)
VALUES
  (92, 55, 2, '查看', 'profile.view', '', '', 55, 1, NOW(), NOW());

-- 授权给所有普通用户组。超级管理员组的 rules='*'，天然拥有全部，不需要加。
UPDATE `zsk_auth_group`
SET `rules` = IF(IFNULL(`rules`, '') = '', '92', CONCAT(`rules`, ',92')),
    `update_time` = NOW()
WHERE `rules` <> '*'
  AND (`rules` IS NULL OR `rules` = '' OR FIND_IN_SET('92', `rules`) = 0);
