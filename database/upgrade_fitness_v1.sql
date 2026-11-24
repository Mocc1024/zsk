-- ============================================================
--  全民健身模块 V1：业务表（7 张，全部新建，不改动任何原有表）
--  适用：已执行 upgrade_front_v3.sql（存在 zsk_user）的库
--  特性：幂等建表；所有表 InnoDB / utf8mb4；关联 user_id 用字段+索引，
--        不建物理外键（沿用项目惯例）
--  执行：php scripts/run_sql.php database/upgrade_fitness_v1.sql
--  配套：upgrade_fitness_menu.sql（菜单权限，本脚本之后执行）
-- ============================================================

SET NAMES utf8mb4;

-- ---------------- 门店表 ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_store` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`        varchar(100) NOT NULL DEFAULT '' COMMENT '门店名称',
  `address`     varchar(255) NOT NULL DEFAULT '' COMMENT '门店地址',
  `status`      tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0停用',
  `sort`        int(11) NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-门店表';

-- ---------------- 教练表（关联 zsk_user） ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_coach` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '关联 zsk_user.id（前台登录账号）',
  `name`         varchar(50)  NOT NULL DEFAULT '' COMMENT '教练姓名',
  `mobile`       varchar(20)  NOT NULL DEFAULT '' COMMENT '档案手机号（可编辑，不回写 zsk_user）',
  `avatar`       varchar(255) NOT NULL DEFAULT '' COMMENT '头像URL',
  `birth_date`   date DEFAULT NULL COMMENT '出生日期',
  `bio`          varchar(1000) NOT NULL DEFAULT '' COMMENT '个人介绍',
  `certificates` text COMMENT '证书照片URL列表（JSON数组）',
  `store_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '绑定门店ID',
  `status`       tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1在职 2离职',
  `leave_time`   datetime DEFAULT NULL COMMENT '离职时间',
  `create_time`  datetime DEFAULT NULL,
  `update_time`  datetime DEFAULT NULL,
  `delete_time`  datetime DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  KEY `idx_store` (`store_id`),
  KEY `idx_status` (`status`),
  KEY `idx_name` (`name`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-教练表';

-- ---------------- 会员/学员表（关联 zsk_user） ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_member` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`       int(10) unsigned NOT NULL DEFAULT 0 COMMENT '关联 zsk_user.id（前台登录账号）',
  `name`          varchar(50)  NOT NULL DEFAULT '' COMMENT '会员姓名',
  `mobile`        varchar(20)  NOT NULL DEFAULT '' COMMENT '档案手机号（可编辑，不回写 zsk_user）',
  `avatar`        varchar(255) NOT NULL DEFAULT '' COMMENT '头像URL',
  `birth_date`    date DEFAULT NULL COMMENT '出生日期',
  `bio`           varchar(1000) NOT NULL DEFAULT '' COMMENT '个人介绍',
  `store_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '绑定门店ID',
  `enterprise_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '绑定企业ID，0为未绑定',
  `coach_id`      int(10) unsigned NOT NULL DEFAULT 0 COMMENT '绑定教练ID，0为未绑定',
  `lesson_balance` int(10) NOT NULL DEFAULT 0 COMMENT '课时余额（按次数计，可为负仅由冲销产生）',
  `create_time`   datetime DEFAULT NULL,
  `update_time`   datetime DEFAULT NULL,
  `delete_time`   datetime DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  KEY `idx_store` (`store_id`),
  KEY `idx_enterprise` (`enterprise_id`),
  KEY `idx_coach` (`coach_id`),
  KEY `idx_name` (`name`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-会员（学员）表';

-- ---------------- 企业表 ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_enterprise` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name`        varchar(100) NOT NULL DEFAULT '' COMMENT '企业名称',
  `intro`       varchar(1000) NOT NULL DEFAULT '' COMMENT '企业介绍',
  `guild_name`  varchar(100) NOT NULL DEFAULT '' COMMENT '公会名称',
  `guild_intro` varchar(1000) NOT NULL DEFAULT '' COMMENT '公会介绍',
  `store_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '绑定门店ID（新增时必填）',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `delete_time` datetime DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_store` (`store_id`),
  KEY `idx_name` (`name`),
  KEY `idx_delete` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-企业表';

-- ---------------- 会员卡记录（业绩流水，只读） ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_member_card` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `member_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '会员ID',
  `store_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '办理时学员绑定门店ID（业绩归属快照）',
  `card_date`   datetime DEFAULT NULL COMMENT '选择日期（精确到秒，业绩归属日期）',
  `start_date`  date DEFAULT NULL COMMENT '有效期开始日期',
  `end_date`    date DEFAULT NULL COMMENT '有效期结束日期',
  `amount`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '办卡金额（负数为冲销记录）',
  `reverse_of`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冲销的原记录ID，0为正常记录',
  `create_time` datetime DEFAULT NULL COMMENT '实际录入时间',
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`),
  KEY `idx_store_date` (`store_id`, `card_date`),
  KEY `idx_reverse` (`reverse_of`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-会员卡记录（业绩流水只读）';

-- ---------------- 绩效记录（业绩流水 + 课时增加，只读） ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_performance` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `coach_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '教练ID',
  `member_id`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '学员ID（单选，必须为该教练名下已绑定学员）',
  `store_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '记录时教练绑定门店ID（业绩归属快照）',
  `perf_date`   datetime DEFAULT NULL COMMENT '选择日期（精确到秒，业绩归属日期）',
  `amount`      decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '卖课金额（负数为冲销记录）',
  `lessons`     int(10) NOT NULL DEFAULT 0 COMMENT '给该学员增加的课时次数（负数为冲销记录）',
  `reverse_of`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冲销的原记录ID，0为正常记录',
  `create_time` datetime DEFAULT NULL COMMENT '实际录入时间',
  PRIMARY KEY (`id`),
  KEY `idx_coach` (`coach_id`),
  KEY `idx_member` (`member_id`),
  KEY `idx_store_date` (`store_id`, `perf_date`),
  KEY `idx_reverse` (`reverse_of`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-教练绩效记录（业绩流水只读）';

-- ---------------- 消课记录（课时减少） ----------------
CREATE TABLE IF NOT EXISTS `zsk_fitness_lesson_consume` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `member_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '会员ID',
  `store_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '消课时会员绑定门店ID（已上课时归属快照）',
  `consume_date` datetime DEFAULT NULL COMMENT '上课日期时间（精确到秒）',
  `lessons`      int(10) NOT NULL DEFAULT 0 COMMENT '本次消耗课时次数',
  `remark`       varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time`  datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`),
  KEY `idx_store` (`store_id`),
  KEY `idx_date` (`consume_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全民健身-消课记录';

-- ============================================================
--  回滚（默认注释，按需手工执行；执行前请备份）
-- DROP TABLE IF EXISTS `zsk_fitness_lesson_consume`;
-- DROP TABLE IF EXISTS `zsk_fitness_performance`;
-- DROP TABLE IF EXISTS `zsk_fitness_member_card`;
-- DROP TABLE IF EXISTS `zsk_fitness_enterprise`;
-- DROP TABLE IF EXISTS `zsk_fitness_member`;
-- DROP TABLE IF EXISTS `zsk_fitness_coach`;
-- DROP TABLE IF EXISTS `zsk_fitness_store`;
-- ============================================================
