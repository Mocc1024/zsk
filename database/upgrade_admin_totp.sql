-- 后台管理员：TOTP 两步验证字段
--
-- 幂等：每列先查 information_schema，已存在就跳过（MySQL 8.0 的 ADD COLUMN
-- 不支持 IF NOT EXISTS，只能走 PREPARE 动态语句这条路）。
--
-- 执行：php scripts/run_sql.php database/upgrade_admin_totp.sql

SET @db := DATABASE();

-- totp_secret：Base32 密钥。绑定后才有值，"点启用但没扫码"时也会先落一份，
-- 这样 enable 步骤校验的就是用户刚刚扫进去的那把密钥。
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'zsk_admin' AND COLUMN_NAME = 'totp_secret');
SET @sql := IF(@exists = 0, 'ALTER TABLE `zsk_admin` ADD COLUMN `totp_secret` varchar(64) NOT NULL DEFAULT '''' COMMENT ''TOTP 密钥（Base32）'' AFTER `last_login_time`', 'SELECT 1');
PREPARE s1 FROM @sql;
EXECUTE s1;
DEALLOCATE PREPARE s1;

-- totp_enabled：是否强制两步验证。0 的账号走原来的单步登录，存量账号不受影响。
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'zsk_admin' AND COLUMN_NAME = 'totp_enabled');
SET @sql := IF(@exists = 0, 'ALTER TABLE `zsk_admin` ADD COLUMN `totp_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''是否启用两步验证'' AFTER `totp_secret`', 'SELECT 1');
PREPARE s1 FROM @sql;
EXECUTE s1;
DEALLOCATE PREPARE s1;

-- totp_recovery：恢复码（8 个，bcrypt 散列后存 JSON 数组）。
-- 明文只在启用成功那一刻返回一次，之后库里查不到，手机丢了只能靠它登录。
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'zsk_admin' AND COLUMN_NAME = 'totp_recovery');
SET @sql := IF(@exists = 0, 'ALTER TABLE `zsk_admin` ADD COLUMN `totp_recovery` text NULL COMMENT ''恢复码（bcrypt 散列 JSON），一次性使用'' AFTER `totp_enabled`', 'SELECT 1');
PREPARE s1 FROM @sql;
EXECUTE s1;
DEALLOCATE PREPARE s1;
