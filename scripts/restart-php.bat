@echo off
chcp 65001 >nul
title ZSK 后台 - 重启 PHP 与 Nginx

echo ============================================================
echo  重启小皮面板 PHP 8.2.9（php-cgi）并重载 Nginx
echo  用途：加载新增的 php.ini（mbstring / pdo_mysql）与伪静态规则
echo  请右键本文件，选择"以管理员身份运行"
echo ============================================================
echo.

set PHP_DIR=E:\phpstudy_pro\Extensions\php\php8.2.9nts
set NGINX_DIR=E:\phpstudy_pro\Extensions\Nginx1.15.11

echo [1/3] 结束现有 php-cgi 进程...
taskkill /F /IM php-cgi.exe >nul 2>&1

echo [2/3] 启动 php-cgi（127.0.0.1:9001，加载 %PHP_DIR%\php.ini）...
start "" /B "%PHP_DIR%\php-cgi.exe" -b 127.0.0.1:9001 -c "%PHP_DIR%\php.ini"

echo [3/3] 重载 Nginx 配置...
"%NGINX_DIR%\nginx.exe" -p "%NGINX_DIR%" -s reload

timeout /t 2 >nul

echo.
echo 完成！现在访问： http://www.zsk.com/admin/
echo 账号：admin  密码：admin123
echo.
pause
