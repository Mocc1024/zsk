<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 请求上下文：保存当前登录管理员信息
 */
class Context
{
    /**
     * @var array|null
     */
    protected static ?array $admin = null;

    /**
     * 当前登录的 C 端用户（由 UserAuth 中间件写入）
     * @var array|null
     */
    protected static ?array $user = null;

    /**
     * 当前调用方开放应用（由 OpenAuth 中间件写入）
     * @var array|null
     */
    protected static ?array $openApp = null;

    public static function setAdmin(array $admin): void
    {
        self::$admin = $admin;
    }

    public static function admin(): ?array
    {
        return self::$admin;
    }

    public static function adminId(): int
    {
        return (int) (self::$admin['id'] ?? 0);
    }

    public static function adminName(): string
    {
        return (string) (self::$admin['nickname'] ?? self::$admin['username'] ?? '系统');
    }

    /**
     * 是否超级管理员：所属任一用户组的 rules='*'
     */
    public static function isSuper(): bool
    {
        return Permission::isSuper(self::$admin);
    }

    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function userId(): int
    {
        return (int) (self::$user['id'] ?? 0);
    }

    public static function userNickname(): string
    {
        return (string) (self::$user['nickname'] ?? self::$user['username'] ?? '');
    }

    public static function setOpenApp(?array $app): void
    {
        self::$openApp = $app;
    }

    public static function openApp(): ?array
    {
        return self::$openApp;
    }

    public static function openAppId(): int
    {
        return (int) (self::$openApp['id'] ?? 0);
    }
}
