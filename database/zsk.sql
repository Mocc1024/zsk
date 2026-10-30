-- =============================================================
--  ZSK 后台管理系统 - 数据库初始化脚本
--  数据库：zsk   账号：root / root
--  说明：可重复执行（先 DROP 再 CREATE）
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `zsk_operation_log`;
DROP TABLE IF EXISTS `zsk_content`;
DROP TABLE IF EXISTS `zsk_category`;
DROP TABLE IF EXISTS `zsk_admin`;

-- ----------------------------
-- 管理员表
-- ----------------------------
CREATE TABLE `zsk_admin` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username`        VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '登录账号',
  `password`        VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '密码(bcrypt)',
  `nickname`        VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar`          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '头像',
  `email`           VARCHAR(100)    NOT NULL DEFAULT '' COMMENT '邮箱',
  `status`          TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `last_login_ip`   VARCHAR(45)     NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` DATETIME        NULL DEFAULT NULL COMMENT '最后登录时间',
  `create_time`     DATETIME        NULL DEFAULT NULL COMMENT '创建时间',
  `update_time`     DATETIME        NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time`     DATETIME        NULL DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- ----------------------------
-- 内容分类表
-- ----------------------------
CREATE TABLE `zsk_category` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`        VARCHAR(60)   NOT NULL DEFAULT '' COMMENT '分类名称',
  `slug`        VARCHAR(60)   NOT NULL DEFAULT '' COMMENT '英文标识',
  `description` VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '分类描述',
  `sort`        INT           NOT NULL DEFAULT 0 COMMENT '排序 越大越前',
  `status`      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` DATETIME      NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` DATETIME      NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time` DATETIME      NULL DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容分类表';

-- ----------------------------
-- 内容主表
-- ----------------------------
CREATE TABLE `zsk_content` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT COMMENT '主键',
  `category_id`  INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT '分类ID',
  `title`        VARCHAR(200)   NOT NULL DEFAULT '' COMMENT '标题',
  `author`       VARCHAR(50)    NOT NULL DEFAULT '' COMMENT '作者',
  `cover`        VARCHAR(255)   NOT NULL DEFAULT '' COMMENT '封面图URL',
  `tags`         VARCHAR(255)   NOT NULL DEFAULT '' COMMENT '标签 逗号分隔',
  `summary`      VARCHAR(500)   NOT NULL DEFAULT '' COMMENT '摘要',
  `content`      MEDIUMTEXT     NULL COMMENT '正文内容',
  `status`       TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '状态 0草稿 1已发布 2已下架',
  `is_top`       TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '是否置顶 1是 0否',
  `views`        INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT '浏览量',
  `sort`         INT            NOT NULL DEFAULT 0 COMMENT '排序 越大越前',
  `publish_time` DATETIME       NULL DEFAULT NULL COMMENT '发布时间',
  `create_time`  DATETIME       NULL DEFAULT NULL COMMENT '创建时间',
  `update_time`  DATETIME       NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time`  DATETIME       NULL DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create` (`create_time`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容表';

-- ----------------------------
-- 操作日志表
-- ----------------------------
CREATE TABLE `zsk_operation_log` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键',
  `admin_id`    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '管理员ID',
  `admin_name`  VARCHAR(50)   NOT NULL DEFAULT '' COMMENT '管理员名称',
  `module`      VARCHAR(30)   NOT NULL DEFAULT '' COMMENT '模块',
  `action`      VARCHAR(50)   NOT NULL DEFAULT '' COMMENT '动作',
  `description` VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '描述',
  `ip`          VARCHAR(45)   NOT NULL DEFAULT '' COMMENT 'IP',
  `create_time` DATETIME      NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_create` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志表';

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- 初始管理员（admin / admin123、editor / editor123）
-- ----------------------------
-- 权限全部由所属用户组（zsk_auth_group.rules）实时派生，
-- 管理员表不再保存权限字段，避免在两处维护同一份权限
INSERT INTO `zsk_admin` (`id`, `username`, `password`, `nickname`, `avatar`, `email`, `status`, `create_time`, `update_time`) VALUES
(1, 'admin',  '$2y$10$vJWdKz/j/5//zDiDSTB3oe94lX9BbKZKR2oQ/XbMtec.Qtx0toRVe', '超级管理员', '', 'admin@zsk.com',  1, NOW(), NOW()),
(2, 'editor', '$2y$10$9SpTMZAwMUx/TsPeRU9cee37d8fGe2.WLYEPf/P4mTQOYb6VAFz/m', '内容编辑',   '', 'editor@zsk.com', 1, NOW(), NOW());

-- ----------------------------
-- 初始分类
-- ----------------------------
INSERT INTO `zsk_category` (`id`, `name`, `slug`, `description`, `sort`, `status`, `create_time`, `update_time`) VALUES
(1, '技术前沿', 'tech',      '前沿技术、架构实践与研发经验', 100, 1, NOW(), NOW()),
(2, '产品介绍', 'product',   '产品能力、版本特性介绍',       90,  1, NOW(), NOW()),
(3, '行业动态', 'industry',  '行业趋势与市场洞察',           80,  1, NOW(), NOW()),
(4, '公司新闻', 'news',      '公司动态与活动报道',           70,  1, NOW(), NOW()),
(5, '帮助文档', 'docs',      '使用说明与常见问题',           60,  1, NOW(), NOW());

-- ----------------------------
-- 初始内容
-- ----------------------------
INSERT INTO `zsk_content` (`category_id`, `title`, `author`, `cover`, `tags`, `summary`, `content`, `status`, `is_top`, `views`, `sort`, `publish_time`, `create_time`, `update_time`) VALUES
(1, 'ThinkPHP 8 全新特性解析', '技术部', '', 'ThinkPHP,PHP,框架', 'ThinkPHP 8 在性能、容器依赖注入、ORM 能力上进行了全面升级，本文带你快速了解核心变化。', '<p>ThinkPHP 8 基于 PHP 8.0+ 重构，引入了更完善的依赖注入容器、更快的路由解析与更简洁的模型操作方式。</p><p>主要特性：</p><ul><li>原生支持 PHP 8 属性与枚举</li><li>ORM 支持更丰富的关联查询</li><li>内置多应用、中间件、事件机制</li></ul>', 1, 1, 1286, 100, NOW() - INTERVAL 1 DAY,  NOW() - INTERVAL 6 DAY,  NOW() - INTERVAL 6 DAY),
(1, 'React 18 并发渲染实践', '前端组', '', 'React,前端,性能', '并发渲染让大型列表与复杂交互保持流畅，本文总结落地经验与踩坑记录。', '<p>React 18 带来的并发特性（Concurrent Features）可以显著提升复杂页面的响应速度。</p><p>关键点：useTransition、Suspense、自动批处理。</p>', 1, 0, 962, 90, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY),
(2, '数据看板能力正式上线', '产品部', '', '产品,看板,数据', '全新数据看板支持多维统计与趋势分析，帮助运营人员实时掌握内容表现。', '<p>数据看板提供内容总量、发布趋势、分类分布、热门内容排行等核心指标。</p>', 1, 0, 745, 88, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY),
(2, '权限管理模块使用说明', '产品部', '', '权限,安全,产品', '基于角色与细粒度菜单的权限控制方案，支持一键配置。', '<p>系统采用 JWT 令牌 + 中间件校验的方式进行接口鉴权，前端按权限动态渲染菜单。</p>', 1, 0, 512, 80, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY),
(3, '2026 年行业数字化趋势报告', '市场部', '', '行业,趋势,报告', '报告从技术、市场、组织三个维度解析数字化转型的关键路径。', '<p>数字化进入深水区，数据资产化与智能化运营成为核心议题。</p>', 1, 0, 1580, 95, NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 9 DAY),
(3, 'AI 驱动的内容生产新模式', '市场部', '', 'AI,AIGC,内容', 'AIGC 正在重塑内容生产流程，效率与质量同步提升。', '<p>从选题、撰写到分发，AI 已贯穿内容生产全链路。</p>', 1, 0, 1342, 92, NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY),
(4, '公司年度技术交流会圆满结束', '行政部', '', '活动,公司,交流', '本次交流会共设 12 场主题分享，线上线下参与人数突破 3000 人。', '<p>技术交流会聚焦云原生、前端工程化与数据智能三大方向。</p>', 1, 0, 688, 70, NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 12 DAY),
(4, '团队建设活动精彩回顾', '行政部', '', '团队,活动,文化', '通过户外拓展与主题沙龙，进一步增强团队凝聚力。', '<p>活动涵盖破冰游戏、主题分享与公益环节。</p>', 2, 0, 320, 60, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 15 DAY, NOW() - INTERVAL 15 DAY),
(5, '快速上手：三步搭建内容站点', '技术支持', '', '文档,教程,入门', '从创建分类到发布内容，只需三步即可完成站点初始化。', '<p>第一步：创建分类；第二步：编写内容；第三步：发布上线。</p>', 1, 0, 445, 85, NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 11 DAY),
(5, '常见问题 FAQ 汇总', '技术支持', '', 'FAQ,文档,帮助', '整理账号、内容发布、数据统计等高频问题解答。', '<p>Q：忘记密码怎么办？A：请联系超级管理员重置。</p>', 0, 0, 210, 50, NULL, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
(1, 'MySQL 索引优化实战（草稿）', '技术部', '', 'MySQL,数据库,优化', '本文整理常见索引失效场景与优化思路，仍在完善中。', '<p>待补充：执行计划分析、覆盖索引、最左前缀原则。</p>', 0, 0, 96, 40, NULL, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(2, '新版编辑器功能预告', '产品部', '', '编辑器,预告,产品', '支持富文本、Markdown 双模式与协作编辑，预计下个版本发布。', '<p>功能预告，暂不对外发布。</p>', 0, 0, 58, 30, NULL, NOW(), NOW());

-- ----------------------------
-- 初始日志
-- ----------------------------
INSERT INTO `zsk_operation_log` (`admin_id`, `admin_name`, `module`, `action`, `description`, `ip`, `create_time`) VALUES
(1, '超级管理员', 'system', 'init', '系统初始化完成', '127.0.0.1', NOW() - INTERVAL 6 DAY),
(1, '超级管理员', 'content', 'publish', '发布内容：ThinkPHP 8 全新特性解析', '127.0.0.1', NOW() - INTERVAL 6 DAY),
(2, '内容编辑', 'content', 'create', '创建内容：MySQL 索引优化实战（草稿）', '127.0.0.1', NOW() - INTERVAL 1 DAY),
(1, '超级管理员', 'category', 'create', '创建分类：帮助文档', '127.0.0.1', NOW() - INTERVAL 3 DAY);
