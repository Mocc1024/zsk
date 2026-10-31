-- =============================================================
--  第三阶段（M3.0）前台站点与 C 端用户体系：新增表
--  适用对象：已完成第二阶段、库中存在 zsk_content / zsk_admin 的数据库
--
--  特性：
--    · 全部使用 CREATE TABLE IF NOT EXISTS，可重复执行
--    · 不删除、不修改任何既有数据
--    · 与 zsk_admin（后台管理员）完全隔离，两张体系表不共用
--
--  执行顺序：必须在 upgrade_content_seo.sql 之前或之后均可，无依赖
--  回滚语句见文件末尾（默认注释，需手工执行）
--
--  表清单（14 张）：
--    zsk_user              用户主表
--    zsk_user_oauth        第三方绑定（本阶段建表，通道后置）
--    zsk_user_level        等级规则配置
--    zsk_user_points_log   积分流水
--    zsk_user_exp_log      经验流水
--    zsk_user_signin       签到记录
--    zsk_user_favorite     收藏
--    zsk_user_message      站内消息
--    zsk_user_report       举报
--    zsk_comment           评论
--    zsk_comment_like      评论点赞
--    zsk_content_stat      日粒度统计
--    zsk_open_app          开放应用（AppKey 鉴权，计划书 4.4 未列，M3.1 需要）
--    zsk_sensitive_word    敏感词库（计划书 4.4 未列，M3.5 需要）
-- =============================================================

SET NAMES utf8mb4;

-- =========================================================
-- 1. 用户主表
-- =========================================================
-- 注意 1：mobile 必须 DEFAULT NULL，不能是 NOT NULL DEFAULT ''。
--      唯一索引允许存在多个 NULL，但不允许出现两个空串 ——
--      用空串当默认值的话，第二个没填手机号的用户注册就会撞
--      Duplicate entry '' for key 'uk_mobile'。（M3.3 探针实测踩到）
--
-- 注意 2：username / mobile 的唯一索引会连同软删记录一起占位。
--      这与注销冷却期的设计一致：冷却期内用户名/手机号仍属于原账号，
--      别人不能抢注；冷却期满由清理任务物理删除后才真正释放。
--      因此注销时不需要改写这两个字段。
CREATE TABLE IF NOT EXISTS `zsk_user` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username`        varchar(50)  NOT NULL DEFAULT '' COMMENT '登录账号',
  `mobile`          varchar(20)  DEFAULT NULL COMMENT '手机号（通道后置，字段先建）',
  `email`           varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `password`        varchar(255) NOT NULL DEFAULT '' COMMENT '密码(bcrypt)',
  `nickname`        varchar(50)  NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar`          varchar(255) NOT NULL DEFAULT '' COMMENT '头像URL',
  `gender`          tinyint(1)   NOT NULL DEFAULT 0 COMMENT '性别 0未知 1男 2女',
  `birthday`        date DEFAULT NULL COMMENT '生日',
  `bio`             varchar(255) NOT NULL DEFAULT '' COMMENT '个人简介',
  `status`          tinyint(1)   NOT NULL DEFAULT 1 COMMENT '状态 1正常 0禁用',
  `exp`             int(10) unsigned NOT NULL DEFAULT 0 COMMENT '经验值',
  `level`           smallint(5) unsigned NOT NULL DEFAULT 1 COMMENT '当前等级',
  `points`          int(11)      NOT NULL DEFAULT 0 COMMENT '积分余额',
  `last_login_ip`   varchar(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
  `create_time`     datetime DEFAULT NULL,
  `update_time`     datetime DEFAULT NULL,
  `delete_time`     datetime DEFAULT NULL COMMENT '软删除时间（注销冷却期）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_mobile` (`mobile`),
  KEY `idx_status` (`status`),
  KEY `idx_level` (`level`),
  KEY `idx_points` (`points`),
  KEY `idx_create` (`create_time`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='C端用户主表';

-- =========================================================
-- 2. 第三方账号绑定（本阶段仅建表，OAuth 通道后置）
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_oauth` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `provider`   varchar(20)  NOT NULL DEFAULT '' COMMENT '平台：wechat / qq / weibo',
  `openid`     varchar(100) NOT NULL DEFAULT '' COMMENT '平台唯一标识',
  `unionid`    varchar(100) NOT NULL DEFAULT '' COMMENT '跨应用统一标识',
  `nickname`   varchar(50)  NOT NULL DEFAULT '' COMMENT '平台昵称快照',
  `avatar`     varchar(255) NOT NULL DEFAULT '' COMMENT '平台头像快照',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_openid` (`provider`, `openid`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户第三方账号绑定表';

-- =========================================================
-- 3. 等级规则配置
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_level` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `level`       smallint(5) unsigned NOT NULL DEFAULT 1 COMMENT '等级',
  `name`        varchar(30)  NOT NULL DEFAULT '' COMMENT '等级名称，如 见习读者',
  `icon`        varchar(255) NOT NULL DEFAULT '' COMMENT '等级图标URL',
  `min_exp`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '升级所需经验下限',
  `rights_desc` varchar(500) NOT NULL DEFAULT '' COMMENT '权益说明',
  `status`      tinyint(1)   NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_level` (`level`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户等级规则表';

-- =========================================================
-- 4. 积分流水（余额与流水必须同事务写入）
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_points_log` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `type`        tinyint(1)   NOT NULL DEFAULT 1 COMMENT '类型 1获取 2消耗',
  `points`      int(11)      NOT NULL DEFAULT 0 COMMENT '变动积分（正数，方向由 type 决定）',
  `balance`     int(11)      NOT NULL DEFAULT 0 COMMENT '变动后的余额快照',
  `scene`       varchar(30)  NOT NULL DEFAULT '' COMMENT '场景 register/signin/comment/admin_adjust',
  `remark`      varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `expire_time` datetime DEFAULT NULL COMMENT '过期时间，NULL 表示不过期',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_create` (`user_id`, `create_time`),
  KEY `idx_scene` (`scene`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户积分流水表';

-- =========================================================
-- 5. 经验流水（便于升级追溯）
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_exp_log` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `exp`         int(11)      NOT NULL DEFAULT 0 COMMENT '变动经验',
  `balance`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '变动后的经验余额快照',
  `scene`       varchar(30)  NOT NULL DEFAULT '' COMMENT '场景',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_create` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户经验流水表';

-- =========================================================
-- 6. 签到记录
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_signin` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `date`        date NOT NULL COMMENT '签到日期',
  `days`        smallint(5) unsigned NOT NULL DEFAULT 1 COMMENT '截至当日的连续天数',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`, `date`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户签到记录表';

-- =========================================================
-- 7. 收藏
-- 注意：取消收藏必须走物理删除，不能用软删除 ——
--      唯一索引 (user_id, content_id) 用于保证重复收藏幂等，
--      若改软删，用户取消后再收藏会因索引占位而失败。
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_favorite` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `content_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '内容ID',
  `folder`      varchar(30)  NOT NULL DEFAULT 'default' COMMENT '收藏夹分组',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_content` (`user_id`, `content_id`),
  KEY `idx_content` (`content_id`),
  KEY `idx_folder` (`folder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户收藏表';

-- =========================================================
-- 8. 站内消息
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_message` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '接收用户ID',
  `type`        varchar(20)  NOT NULL DEFAULT '' COMMENT '类型 comment_reply/comment_like/level_up/points/system',
  `title`       varchar(100) NOT NULL DEFAULT '' COMMENT '标题',
  `body`        varchar(500) NOT NULL DEFAULT '' COMMENT '正文',
  `target_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '关联对象ID',
  `is_read`     tinyint(1)   NOT NULL DEFAULT 0 COMMENT '是否已读 0未读 1已读',
  `create_time` datetime DEFAULT NULL,
  `read_time`   datetime DEFAULT NULL COMMENT '阅读时间',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_read` (`user_id`, `is_read`),
  KEY `idx_create` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户站内消息表';

-- =========================================================
-- 9. 举报（同一用户对同一对象只能举报一次）
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_user_report` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`       int(10) unsigned NOT NULL DEFAULT 0 COMMENT '举报人用户ID',
  `target_type`   varchar(20)  NOT NULL DEFAULT '' COMMENT '对象类型 comment / content',
  `target_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '对象ID',
  `reason`        varchar(255) NOT NULL DEFAULT '' COMMENT '举报理由',
  `status`        tinyint(1)   NOT NULL DEFAULT 0 COMMENT '状态 0待处理 1已处理 2已驳回',
  `handle_result` varchar(255) NOT NULL DEFAULT '' COMMENT '处理结果',
  `admin_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '处理人管理员ID',
  `handle_time`   datetime DEFAULT NULL COMMENT '处理时间',
  `create_time`   datetime DEFAULT NULL,
  `update_time`   datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_target` (`user_id`, `target_type`, `target_id`),
  KEY `idx_status` (`status`),
  KEY `idx_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户举报表';

-- =========================================================
-- 10. 评论（楼中楼两级结构）
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_comment` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `content_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '内容ID',
  `pid`         int(10) unsigned NOT NULL DEFAULT 0 COMMENT '父评论ID，0为顶层',
  `root_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '根评论ID，用于按楼聚合',
  `content`     varchar(1000) NOT NULL DEFAULT '' COMMENT '评论内容（已过 HTML 白名单）',
  `like_count`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '点赞数',
  `status`      tinyint(1)   NOT NULL DEFAULT 0 COMMENT '状态 0待审 1通过 2驳回',
  `ip`          varchar(45)  NOT NULL DEFAULT '' COMMENT '发布IP',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_content_status_create` (`content_id`, `status`, `create_time`),
  KEY `idx_root` (`root_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容评论表';

-- =========================================================
-- 11. 评论点赞
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_comment_like` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `comment_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '评论ID',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_comment` (`user_id`, `comment_id`),
  KEY `idx_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论点赞表';

-- =========================================================
-- 12. 内容日粒度统计
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_content_stat` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `content_id`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '内容ID',
  `date`        date NOT NULL COMMENT '统计日期',
  `pv`          int(10) unsigned NOT NULL DEFAULT 0 COMMENT '浏览量',
  `uv`          int(10) unsigned NOT NULL DEFAULT 0 COMMENT '独立访客（当日 IP+UA 哈希去重）',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_content_date` (`content_id`, `date`),
  KEY `idx_date` (`date`),
  KEY `idx_pv` (`pv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容日粒度统计表';

-- =========================================================
-- 13. 开放应用（M3.1 开放 API 的 AppKey 载体）
-- 为什么不写进配置文件：3.2 要求「密钥可轮换 + 按应用限流」，
-- 配置文件做不到运行时轮换，也没法给每个应用配 QPS。
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_open_app` (
  `id`             int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`           varchar(50)  NOT NULL DEFAULT '' COMMENT '应用名称',
  `app_key`        varchar(32)  NOT NULL DEFAULT '' COMMENT '应用标识',
  `app_secret`     varchar(64)  NOT NULL DEFAULT '' COMMENT '应用密钥（签名用）',
  `allow_paths`    varchar(500) NOT NULL DEFAULT '*' COMMENT '允许访问的路径，* 表示全部',
  `qps`            int(10) unsigned NOT NULL DEFAULT 10 COMMENT '每秒请求上限',
  `status`         tinyint(1)   NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `last_used_time` datetime DEFAULT NULL COMMENT '最近调用时间',
  `create_time`    datetime DEFAULT NULL,
  `update_time`    datetime DEFAULT NULL,
  `delete_time`    datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_key` (`app_key`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开放应用表';

-- =========================================================
-- 14. 敏感词库（M3.5 评论过滤）
-- 为什么不硬编码：3.13 要求后台可维护词库，硬编码等于每次改词都要发版。
-- =========================================================
CREATE TABLE IF NOT EXISTS `zsk_sensitive_word` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `word`        varchar(50)  NOT NULL DEFAULT '' COMMENT '敏感词',
  `level`       tinyint(1)   NOT NULL DEFAULT 2 COMMENT '处理级别 1仅提醒 2替换 3拦截并转待审',
  `replacement` varchar(50)  NOT NULL DEFAULT '***' COMMENT '替换文本',
  `status`      tinyint(1)   NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_word` (`word`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='敏感词库表';

-- =========================================================
-- 15. 执行结果校验（应为 14）
-- =========================================================
SELECT COUNT(*) AS created_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'zsk_user','zsk_user_oauth','zsk_user_level','zsk_user_points_log',
    'zsk_user_exp_log','zsk_user_signin','zsk_user_favorite','zsk_user_message',
    'zsk_user_report','zsk_comment','zsk_comment_like','zsk_content_stat',
    'zsk_open_app','zsk_sensitive_word'
  );

-- =========================================================
-- 回滚（默认注释，确认后手工执行；会丢失第三阶段全部用户/互动数据）
-- 注意顺序：先删有外键语义关联的子表，再删主表
-- =========================================================
-- DROP TABLE IF EXISTS `zsk_comment_like`;
-- DROP TABLE IF EXISTS `zsk_comment`;
-- DROP TABLE IF EXISTS `zsk_user_report`;
-- DROP TABLE IF EXISTS `zsk_user_message`;
-- DROP TABLE IF EXISTS `zsk_user_favorite`;
-- DROP TABLE IF EXISTS `zsk_user_signin`;
-- DROP TABLE IF EXISTS `zsk_user_exp_log`;
-- DROP TABLE IF EXISTS `zsk_user_points_log`;
-- DROP TABLE IF EXISTS `zsk_user_level`;
-- DROP TABLE IF EXISTS `zsk_user_oauth`;
-- DROP TABLE IF EXISTS `zsk_user`;
-- DROP TABLE IF EXISTS `zsk_content_stat`;
-- DROP TABLE IF EXISTS `zsk_open_app`;
-- DROP TABLE IF EXISTS `zsk_sensitive_word`;
