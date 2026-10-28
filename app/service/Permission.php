<?php
declare (strict_types = 1);

namespace app\service;

use app\model\Admin;

/**
 * 权限服务
 *
 * 权限点来自 config/permission.php，按管理员单独分配，存在 admin.permissions（JSON 数组）。
 * 超级管理员（role=admin）不读取该字段，直接拥有全部权限。
 */
class Permission
{
    /** 超级管理员在接口里返回的特殊标记 */
    public const ALL = '*';

    /**
     * 完整权限树（用于后台勾选）
     */
    public static function tree(): array
    {
        return (array) config('permission', []);
    }

    /**
     * 所有叶子节点 key（真正的权限点，不含父级分组）
     */
    public static function allKeys(): array
    {
        $keys = [];
        $walk = function (array $nodes) use (&$walk, &$keys): void {
            foreach ($nodes as $node) {
                if (!empty($node['children'])) {
                    $walk($node['children']);
                } elseif (!empty($node['key'])) {
                    $keys[] = (string) $node['key'];
                }
            }
        };
        $walk(self::tree());

        return $keys;
    }

    /**
     * 只保留合法的叶子权限 key，去重并重建索引
     *
     * @param mixed $keys
     */
    public static function normalize($keys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $clean = array_map('strval', $keys);
        $clean = array_values(array_unique(array_filter($clean, static fn ($v) => $v !== '')));

        return array_values(array_intersect($clean, self::allKeys()));
    }

    /**
     * 解码数据库里存的 permissions（可能是 JSON 字符串，也可能是数组）
     *
     * @param mixed $value
     */
    public static function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * 某个管理员拥有的权限 key 列表
     */
    public static function forAdmin(?array $admin): array
    {
        if (!$admin) {
            return [];
        }
        if (($admin['role'] ?? '') === Admin::ROLE_ADMIN) {
            return self::allKeys();
        }

        return self::normalize(self::decode($admin['permissions'] ?? null));
    }

    /**
     * 判断指定管理员是否拥有某权限
     */
    public static function has(?array $admin, string $key): bool
    {
        if (!$admin) {
            return false;
        }
        if (($admin['role'] ?? '') === Admin::ROLE_ADMIN) {
            return true;
        }

        return in_array($key, self::forAdmin($admin), true);
    }

    /**
     * 判断当前登录管理员是否拥有某权限（控制器里直接用这个）
     */
    public static function can(string $key): bool
    {
        return self::has(Context::admin(), $key);
    }

    /**
     * 给前端用的权限列表：超管返回 ['*']，其余返回具体 key
     */
    public static function responseFor(?array $admin): array
    {
        if ($admin && ($admin['role'] ?? '') === Admin::ROLE_ADMIN) {
            return [self::ALL];
        }

        return self::forAdmin($admin);
    }

    /**
     * 存库用的格式：超管一律 NULL，普通账号存 JSON 字符串
     *
     * @param array|null $admin 目标管理员（用于判断是否是超管）
     * @param mixed      $keys
     */
    public static function toStorage(?array $admin, $keys): ?string
    {
        if ($admin && ($admin['role'] ?? '') === Admin::ROLE_ADMIN) {
            return null;
        }

        return json_encode(self::normalize($keys), JSON_UNESCAPED_UNICODE);
    }
}
