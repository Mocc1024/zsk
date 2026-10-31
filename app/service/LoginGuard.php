<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;

/**
 * 登录失败保护：连续失败 5 次锁定 15 分钟
 *
 * 为什么放在服务端而不是前端：前端计数刷新页面就没了，
 * 而且攻击者根本不会走你的前端。
 *
 * 键用 md5(username)：用户名可能含点、下划线之外的字符（如邮箱），
 * 直接拼进缓存键会有分隔符冲突，也可能写出超长键。
 */
class LoginGuard
{
    /** 触发锁定的失败次数 */
    public const MAX_FAIL = 5;

    /** 锁定时长（秒） */
    public const LOCK_TTL = 900;

    /** 失败计数的统计窗口（秒） */
    private const FAIL_TTL = 900;

    /**
     * 是否已锁定，返回剩余秒数（0 表示未锁定）
     */
    public static function lockedSeconds(string $username): int
    {
        $until = (int) Cache::get(self::lockKey($username));
        if ($until <= time()) {
            return 0;
        }
        return $until - time();
    }

    /**
     * 记录一次失败，返回剩余可尝试次数（0 表示已触发锁定）
     */
    public static function fail(string $username): int
    {
        $key   = self::failKey($username);
        $count = (int) Cache::get($key) + 1;
        Cache::set($key, $count, self::FAIL_TTL);

        if ($count >= self::MAX_FAIL) {
            // 锁定值里存的是解锁时间戳，避免再去查缓存的剩余 TTL
            Cache::set(self::lockKey($username), time() + self::LOCK_TTL, self::LOCK_TTL);
            Cache::delete($key);

            return 0;
        }

        return self::MAX_FAIL - $count;
    }

    /**
     * 登录成功后清空计数
     */
    public static function clear(string $username): void
    {
        Cache::delete(self::failKey($username));
        Cache::delete(self::lockKey($username));
    }

    private static function failKey(string $username): string
    {
        return 'login_fail:' . md5($username);
    }

    private static function lockKey(string $username): string
    {
        return 'login_lock:' . md5($username);
    }
}
