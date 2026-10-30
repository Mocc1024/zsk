<?php
/**
 * 执行 SQL 迁移文件
 *
 * Windows 环境通常没有 mysql 客户端，用这个脚本代替命令行导入。
 * 连接信息直接读取项目根目录的 .env（DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS）。
 *
 * 用法：
 *   php scripts/run_sql.php database/upgrade_attachment_folder.sql
 */

declare (strict_types = 1);

$root = dirname(__DIR__);
$file = $argv[1] ?? '';

if ($file === '') {
    fwrite(STDERR, "用法：php scripts/run_sql.php <sql文件路径>" . PHP_EOL);
    exit(1);
}

$path = (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:#', $file)) ? $file : $root . '/' . $file;
if (!is_file($path)) {
    fwrite(STDERR, "找不到文件：$path" . PHP_EOL);
    exit(1);
}

/** 读取 .env */
$env = [];
if (is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$name = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';

if ($name === '') {
    fwrite(STDERR, '.env 中未配置 DB_NAME，无法连接数据库' . PHP_EOL);
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

/** 按分号拆分语句，跳过注释行 */
$statements = [];
$buffer     = '';
foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
        continue;
    }
    $buffer .= $line . PHP_EOL;
    if (str_ends_with($trimmed, ';')) {
        $statements[] = rtrim(trim($buffer), ';');
        $buffer       = '';
    }
}

$ok = 0;
$failed = 0;
foreach ($statements as $sql) {
    $brief = mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 90);
    try {
        $stmt = $pdo->query($sql);
        $ok++;
        echo '√ ' . $brief . PHP_EOL;
        foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
            echo '  → ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    } catch (Throwable $e) {
        $failed++;
        echo '× ' . $brief . PHP_EOL;
        echo '  ! ' . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "完成：成功 {$ok} 条，失败 {$failed} 条" . PHP_EOL;

exit($failed > 0 ? 1 : 0);
