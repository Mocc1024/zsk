<?php
declare (strict_types = 1);

/**
 * 解除前台用户的「连续输错 5 次锁定 15 分钟」
 *
 * 用法：
 *   php scripts/unlock_login.php                # 列出当前被锁定的账号
 *   php scripts/unlock_login.php 用户名          # 按用户名解锁
 *   php scripts/unlock_login.php <md5>          # 按 md5 解锁（账号不存在时用这个）
 *   php scripts/unlock_login.php --all          # 解锁全部
 *
 * 两个要点，不知道会白忙一场：
 *
 * 1. 锁定状态在【缓存】里，不在数据库 —— 改 zsk_user 没有任何用。
 *    键是 login_lock:md5(username) 与 login_fail:md5(username)，见 app/service/LoginGuard.php。
 *
 * 2. 锁定是按【输入的用户名】记的，不管这个账号存不存在。
 *    输错一个不存在的用户名 5 次，照样锁 —— 而用户表里查不到它，
 *    所以这里既能按用户名解锁，也能按 md5 解锁。
 *
 * 缓存驱动是自动探测的（能用 Redis 就用，否则回退文件缓存），
 * 故统一走 Cache 门面，不直连 Redis，避免 CLI 与 Web 驱动不一致时清错地方。
 */

require __DIR__ . '/../vendor/autoload.php';
$app = new \think\App();
$app->initialize();

use app\service\LoginGuard;
use think\facade\Cache;
use think\facade\Db;

$arg = $argv[1] ?? '';

$driver = (string) config('cache.default');
echo "当前缓存驱动：{$driver}\n\n";

/** 用户表里存在的账号中，哪些被锁了 */
function lockedUsers(): array
{
    $locked = [];
    foreach (Db::name('user')->column('username') as $name) {
        $name = (string) $name;
        $sec = LoginGuard::lockedSeconds($name);
        if ($sec > 0) {
            $locked[] = ['username' => $name, 'seconds' => $sec];
        }
    }

    return $locked;
}

/**
 * 缓存里实际存在的锁定键（Redis 驱动下可枚举）
 *
 * 注意：这些键的 md5 可能不属于任何账号（输错不存在的用户名也会锁），
 * 所以必须单独列出来，否则会漏。
 */
function lockKeys(): array
{
    if (config('cache.default') !== 'redis') {
        return [];
    }

    $redis = new \Redis();
    $redis->connect((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
    $prefix = (string) env('REDIS_PREFIX', 'zsk:');

    $out = [];
    foreach ($redis->keys($prefix . 'login_lock:*') as $key) {
        $md5 = str_replace($prefix . 'login_lock:', '', (string) $key);
        $out[] = ['md5' => $md5, 'ttl' => (int) $redis->ttl($key)];
    }

    return $out;
}

function unlockByName(string $username): void
{
    $before = LoginGuard::lockedSeconds($username);
    LoginGuard::clear($username);
    $after = LoginGuard::lockedSeconds($username);

    if ($before === 0) {
        echo "  {$username}：没有被锁，无需处理\n";

        return;
    }
    echo $after === 0
        ? "  {$username}：已解锁（原本还剩 {$before} 秒）\n"
        : "  {$username}：解锁失败，仍剩 {$after} 秒\n";
}

function unlockByMd5(string $md5): void
{
    Cache::delete('login_lock:' . $md5);
    Cache::delete('login_fail:' . $md5);
    echo "  已清除 md5={$md5} 的锁定与失败计数\n";
}

// ---------- 列出 ----------
if ($arg === '') {
    $locked = lockedUsers();
    echo '【用户表内被锁的账号】' . (count($locked) ? count($locked) . ' 个' : '无') . "\n";
    foreach ($locked as $item) {
        printf("  %-20s 剩余 %d 秒（约 %d 分钟）\n", $item['username'], $item['seconds'], (int) ceil($item['seconds'] / 60));
    }

    $keys = lockKeys();
    echo "\n【缓存里的锁定键】" . (count($keys) ? count($keys) . ' 个' : '无') . "\n";
    if (count($keys) === 0 && $driver !== 'redis') {
        echo "  （当前是文件缓存，无法枚举键，只能用用户名解锁）\n";
    }
    foreach ($keys as $k) {
        printf("  md5=%s  剩余 %d 秒（约 %d 分钟）\n", $k['md5'], $k['ttl'], (int) ceil($k['ttl'] / 60));
    }

    echo "\n解锁：php scripts/unlock_login.php 用户名   或   php scripts/unlock_login.php <md5>\n";
    exit(0);
}

// ---------- 全部解锁 ----------
if ($arg === '--all') {
    foreach (lockedUsers() as $item) {
        unlockByName($item['username']);
    }
    foreach (lockKeys() as $k) {
        unlockByMd5($k['md5']);
    }
    echo "完成\n";
    exit(0);
}

// ---------- 按 md5 解锁 ----------
if (preg_match('/^[0-9a-f]{32}$/', $arg)) {
    echo "按 md5 解锁：\n";
    unlockByMd5($arg);
    echo "完成\n";
    exit(0);
}

// ---------- 按用户名解锁 ----------
$username = $arg;
if (!Db::name('user')->where('username', $username)->count()) {
    echo "提示：用户表里没有【{$username}】。\n";
    echo "锁定是按输入的用户名记的，账号不存在也会锁，所以照样清它的缓存键。\n";
}
unlockByName($username);
echo "完成\n";
