-- ============================================================
--  RBAC 权限体系：用户组 / 权限菜单 / 用户-组关联
--  可重复执行（DROP + CREATE + 幂等种子数据）
-- ============================================================

-- ---------------- 权限菜单表 ----------------
DROP TABLE IF EXISTS `zsk_auth_rule`;
CREATE TABLE `zsk_auth_rule` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '规则ID',
  `pid`         int(10) unsigned NOT NULL DEFAULT 0 COMMENT '上级ID，0为顶级菜单',
  `type`        tinyint(1) NOT NULL DEFAULT 1 COMMENT '类型：1菜单 2权限点（按钮/接口）',
  `title`       varchar(60) NOT NULL DEFAULT '' COMMENT '名称',
  `name`        varchar(100) NOT NULL DEFAULT '' COMMENT '权限标识，如 content.list',
  `path`        varchar(150) NOT NULL DEFAULT '' COMMENT '前端路由路径',
  `icon`        varchar(60) NOT NULL DEFAULT '' COMMENT '图标名称',
  `sort`        smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `status`      tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1启用 0禁用',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限菜单表';

-- ---------------- 用户组表 ----------------
DROP TABLE IF EXISTS `zsk_auth_group`;
CREATE TABLE `zsk_auth_group` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户组ID',
  `title`       varchar(60) NOT NULL DEFAULT '' COMMENT '用户组名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '用户组描述',
  `rules`       text COMMENT '权限规则ID集合，逗号分隔；* 表示全部权限',
  `sort`        smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '排序',
  `status`      tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1启用 0禁用',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户组（角色）表';

-- ---------------- 用户与用户组关联表 ----------------
DROP TABLE IF EXISTS `zsk_auth_group_access`;
CREATE TABLE `zsk_auth_group_access` (
  `uid`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '管理员ID',
  `group_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户组ID',
  PRIMARY KEY (`uid`, `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员与用户组关联表';

-- ---------------- admin 表增加所属用户组字段（幂等） ----------------
SET @dbname = DATABASE();
SET @sqltext = (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'zsk_admin' AND COLUMN_NAME = 'group_id') > 0,
  "SELECT 'column group_id exists' AS info",
  "ALTER TABLE `zsk_admin` ADD COLUMN `group_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属用户组ID' AFTER `role`"
));
PREPARE stmt FROM @sqltext;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------- 种子：权限菜单 ----------------
INSERT INTO `zsk_auth_rule` (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `sort`, `status`, `create_time`, `update_time`) VALUES
(10, 0,  1, '数据看板',   'dashboard',  '/dashboard',  'DashboardOutlined', 100, 1, NOW(), NOW()),
(11, 10, 2, '查看看板',   'dashboard.view', '',         '',                   1, 1, NOW(), NOW()),
(20, 0,  1, '内容管理',   'content',    '/content',    'FileTextOutlined',   90, 1, NOW(), NOW()),
-- 内容相关的操作权限点挂在「内容列表」#201 下（该菜单由 upgrade_menu_content_list.sql 创建）。
-- 注意：不要把 #201 这类「带子节点的菜单」写进用户组 rules —— 授予菜单 ID 等于
--       递归授予其下全部权限点，只读角色会被一并放大，详见 upgrade_menu_content_actions.sql
(21, 201, 2, '查看列表',   'content.list',   '',         '',                  10, 1, NOW(), NOW()),
(22, 201, 2, '新增内容',   'content.create', '',         '',                   9, 1, NOW(), NOW()),
(23, 201, 2, '编辑内容',   'content.update', '',         '',                   8, 1, NOW(), NOW()),
(24, 201, 2, '删除内容',   'content.delete', '',         '',                   7, 1, NOW(), NOW()),
(25, 201, 2, '批量删除',   'content.batch-delete', '',   '',                  6, 1, NOW(), NOW()),
(26, 201, 2, '发布/下架', 'content.status',  '',         '',                   5, 1, NOW(), NOW()),
(27, 201, 2, '置顶操作',   'content.top',    '',         '',                   4, 1, NOW(), NOW()),
(30, 0,  1, '分类管理',   'category',   '/category',   'AppstoreOutlined',    80, 1, NOW(), NOW()),
(31, 30, 2, '查看列表',   'category.list',   '',        '',                  10, 1, NOW(), NOW()),
(32, 30, 2, '新增分类',   'category.create', '',        '',                    9, 1, NOW(), NOW()),
(33, 30, 2, '编辑分类',   'category.update', '',        '',                    8, 1, NOW(), NOW()),
(34, 30, 2, '删除分类',   'category.delete', '',        '',                   7, 1, NOW(), NOW()),
(35, 30, 2, '启用/禁用', 'category.status',  '',        '',                    6, 1, NOW(), NOW()),
(40, 0,  1, '操作日志',   'log',        '/log',       'HistoryOutlined',       70, 1, NOW(), NOW()),
(41, 40, 2, '查看日志',   'log.list',   '',            '',                    10, 1, NOW(), NOW()),
(42, 40, 2, '清空日志',   'log.clear',  '',            '',                     9, 1, NOW(), NOW()),
(50, 0,  1, '系统管理',   'system',     '',            'SettingOutlined',      60, 1, NOW(), NOW()),
(51, 50, 1, '管理员管理', 'admin',      '/admin',     'TeamOutlined',         59, 1, NOW(), NOW()),
(61, 51, 2, '查看列表',   'admin.list',   '',          '',                   10, 1, NOW(), NOW()),
(62, 51, 2, '新增管理员', 'admin.create', '',          '',                    9, 1, NOW(), NOW()),
(63, 51, 2, '编辑管理员', 'admin.update', '',          '',                    8, 1, NOW(), NOW()),
(64, 51, 2, '删除管理员', 'admin.delete', '',          '',                    7, 1, NOW(), NOW()),
(65, 51, 2, '启用/禁用', 'admin.status',  '',          '',                   6, 1, NOW(), NOW()),
(52, 50, 1, '用户组管理', 'group',      '/group',     'SafetyCertificateOutlined', 58, 1, NOW(), NOW()),
(71, 52, 2, '查看列表',   'group.list',   '',          '',                   10, 1, NOW(), NOW()),
(72, 52, 2, '新增用户组', 'group.create', '',          '',                    9, 1, NOW(), NOW()),
(73, 52, 2, '编辑用户组', 'group.update', '',          '',                    8, 1, NOW(), NOW()),
(74, 52, 2, '删除用户组', 'group.delete', '',          '',                   7, 1, NOW(), NOW()),
(75, 52, 2, '启用/禁用', 'group.status',  '',          '',                  6, 1, NOW(), NOW()),
(76, 52, 2, '分配权限',   'group.authorize', '',        '',                   5, 1, NOW(), NOW()),
(53, 50, 1, '权限菜单',   'permission', '/permission',  'MenuOutlined',      57, 1, NOW(), NOW()),
(81, 53, 2, '查看列表',   'permission.list',   '',       '',                10, 1, NOW(), NOW()),
(82, 53, 2, '新增菜单',   'permission.create', '',       '',                 9, 1, NOW(), NOW()),
(83, 53, 2, '编辑菜单',   'permission.update', '',       '',                 8, 1, NOW(), NOW()),
(84, 53, 2, '删除菜单',   'permission.delete', '',       '',                 7, 1, NOW(), NOW()),
(85, 53, 2, '启用/禁用', 'permission.status',  '',       '',                 6, 1, NOW(), NOW());

-- ---------------- 种子：默认用户组 ----------------
INSERT INTO `zsk_auth_group` (`id`, `title`, `description`, `rules`, `sort`, `status`, `create_time`, `update_time`) VALUES
(1, '超级管理员', '系统内置，拥有全部权限', '*', 100, 1, NOW(), NOW()),
(2, '内容运营',   '可管理内容与分类，查看数据看板', '11,21,22,23,24,25,26,27,31,32,33,34,35', 90, 1, NOW(), NOW()),
(3, '只读访客',   '仅可查看看板、内容与分类列表', '11,21,31,41', 80, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `description` = VALUES(`description`),
  `rules` = VALUES(`rules`), `sort` = VALUES(`sort`), `update_time` = NOW();

-- ---------------- 现有账号并入默认用户组 ----------------
UPDATE `zsk_admin` SET `group_id` = 1 WHERE `id` = 1;
UPDATE `zsk_admin` SET `group_id` = 2 WHERE `id` = 2;
INSERT IGNORE INTO `zsk_auth_group_access` (`uid`, `group_id`) VALUES (1, 1), (2, 2);
