<?php
declare (strict_types = 1);

namespace app\service;

use app\model\Admin;

/**
 * 请求上下文：保存当前登录管理员信息
 */
class Context
{
    /**
     * @var array|null
     */
    protected static ?array $admin = null;

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
     * 是否超级管理员
     */
    public static function isSuper(): bool
    {
        return (self::$admin['role'] ?? '') === Admin::ROLE_ADMIN;
    }
}
