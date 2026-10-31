<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;

/**
 * C 端用户令牌黑名单（登出 / 封禁 / 改密后让旧令牌立即失效）
 *
 * 为什么需要它：JWT 签发后在有效期内无法单方面作废，
 * 登出若只在前端丢弃 token，服务端仍然认这张令牌。
 * 这里把「已作废但还没过期」的令牌存进 Redis，
 * 有效期 = 该令牌剩余寿命，到期自动清理，不会无限增长。
 *
 * 存的不是令牌原文，是 sha256 —— 避免 Redis 里出现可用凭据。
 */
class TokenBlacklist
{
    private const PREFIX = 'token_blacklist:';

    /**
     * 加入黑名单
     *
     * @param string $token 令牌原文
     * @param int    $ttl   存活秒数；>0 时以该值为准，否则自动取令牌剩余寿命
     */
    public static function add(string $token, int $ttl = 0): void
    {
        if ($ttl <= 0) {
            $payload = Jwt::decode($token);
            $ttl     = $payload !== null ? max(1, (int) ($payload['exp'] ?? 0) - time()) : 3600;
        }

        Cache::set(self::PREFIX . self::hash($token), time(), $ttl);
    }

    /**
     * 是否在黑名单中
     */
    public static function has(string $token): bool
    {
        return Cache::has(self::PREFIX . self::hash($token));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
