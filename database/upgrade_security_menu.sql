-- 后台侧边栏增加「安全设置」菜单（两步验证 / TOTP 的入口）
--
-- 原先只放在右上角用户下拉里，按用户要求改到权限菜单树中，这样可以按用户组授权。
--
-- 一个容易踩的点：菜单对某用户是否可见，取决于「它下面有没有被授权的权限点」。
-- Permission::filterMenuTree() 判定用的是 type=2 权限点的 name，菜单自身的 name
-- （这里是 'security'）并不参与判定，所以必须同时建一个子权限点，并把它写进用户组 rules。
--
-- 执行：php scripts/run_sql.php database/upgrade_security_menu.sql

-- 菜单节点：挂在「系统管理」(id=50) 下，sort=56 排在权限菜单(57)之后
INSERT IGNORE INTO `zsk_auth_rule`
  (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`)
VALUES
  (54, 50, 1, '安全设置', 'security', '/security', 'SafetyCertificateOutlined', 56, 1, NOW(), NOW());

-- 子权限点：决定菜单是否出现在侧边栏
INSERT IGNORE INTO `zsk_auth_rule`
  (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`)
VALUES
  (91, 54, 2, '查看', 'security.view', '', '', 56, 1, NOW(), NOW());

-- 授权给所有普通用户组。超级管理员组的 rules='*'，天然拥有全部，不需要加。
UPDATE `zsk_auth_group`
SET `rules` = IF(IFNULL(`rules`, '') = '', '91', CONCAT(`rules`, ',91')),
    `update_time` = NOW()
WHERE `rules` <> '*'
  AND (`rules` IS NULL OR `rules` = '' OR FIND_IN_SET('91', `rules`) = 0);
