<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 轻量级 JWT（HS256）签发与校验
 */
class Jwt
{
    /** 令牌角色：后台管理员 / C 端用户，两套体系互不串用 */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER  = 'user';

    /** 令牌类型：Refresh Token 不得当作 Access Token 访问业务接口 */
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /**
     * 获取密钥
     */
    protected static function secret(): string
    {
        return (string) (env('JWT_SECRET') ?: 'ZskAdminSecretKey');
    }

    /**
     * 签发令牌
     */
    public static function encode(array $payload, int $ttl = 0): string
    {
        $ttl = $ttl > 0 ? $ttl : (int) (env('JWT_TTL') ?: 7200);
        $header  = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now     = time();
        $payload = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        ];
        $segments[] = self::base64UrlEncode(
            hash_hmac('sha256', implode('.', $segments), self::secret(), true)
        );

        return implode('.', $segments);
    }

    /**
     * 校验令牌，成功返回载荷数组，失败返回 null
     */
    public static function decode(?string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$head64, $body64, $sign64] = $parts;
        $expect = self::base64UrlEncode(hash_hmac('sha256', $head64 . '.' . $body64, self::secret(), true));
        if (!hash_equals($expect, $sign64)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($body64), true);
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * 取令牌角色。旧令牌没有该字段时返回空字符串，
     * 调用方据此拒绝——不猜测、不兜底放行，避免跨体系串用。
     */
    public static function roleOf(?array $payload): string
    {
        return (string) ($payload['role'] ?? '');
    }

    /**
     * 取令牌类型，缺省按 access 处理
     */
    public static function typeOf(?array $payload): string
    {
        return (string) ($payload['type'] ?? self::TYPE_ACCESS);
    }

    /**
     * C 端用户 Access Token 有效期（秒）
     */
    public static function userAccessTtl(): int
    {
        return (int) (env('USER_JWT_TTL') ?: 7200);
    }

    /**
     * C 端用户 Refresh Token 有效期（秒）
     */
    public static function userRefreshTtl(): int
    {
        return (int) (env('USER_JWT_REFRESH_TTL') ?: 604800);
    }

    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
