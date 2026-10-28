# 修复小皮面板运行环境（需要管理员权限）
# 1. 重启 php-cgi，使其加载新增的 php.ini（mbstring / pdo_mysql）
# 2. 重载 Nginx，使 ThinkPHP 伪静态规则生效

$phpDir   = 'E:\phpstudy_pro\Extensions\php\php8.2.9nts'
$nginxDir = 'E:\phpstudy_pro\Extensions\Nginx1.15.11'
$log      = 'E:\M\zsk\runtime\fix-env.log'

"===== $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') =====" | Out-File -FilePath $log -Encoding utf8

# 1. 结束现有 php-cgi
Get-Process php-cgi -ErrorAction SilentlyContinue | ForEach-Object {
    "kill php-cgi PID $($_.Id)" | Out-File -FilePath $log -Append -Encoding utf8
    Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue
}
Start-Sleep -Seconds 2

# 2. 用新的 php.ini 启动 php-cgi
$phpCgi = Join-Path $phpDir 'php-cgi.exe'
$ini    = Join-Path $phpDir 'php.ini'
Start-Process -FilePath $phpCgi -ArgumentList "-b","127.0.0.1:9001","-c","$ini" -WindowStyle Hidden
Start-Sleep -Seconds 2
"start php-cgi 127.0.0.1:9001" | Out-File -FilePath $log -Append -Encoding utf8

# 3. 重载 Nginx
& "$nginxDir\nginx.exe" -p "$nginxDir" -s reload 2>&1 | Out-File -FilePath $log -Append -Encoding utf8
Start-Sleep -Seconds 2

# 4. 自检
try {
    $resp = Invoke-RestMethod -Uri 'http://www.zsk.com/api/auth/login' -Method Post `
        -Body 'username=admin&password=admin123' -ContentType 'application/x-www-form-urlencoded'
    "login code = $($resp.code) msg = $($resp.msg)" | Out-File -FilePath $log -Append -Encoding utf8
} catch {
    "login error: $($_.Exception.Message)" | Out-File -FilePath $log -Append -Encoding utf8
}

"done" | Out-File -FilePath $log -Append -Encoding utf8
