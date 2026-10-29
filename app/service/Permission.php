<?php
declare (strict_types = 1);

namespace app\service;

use app\model\AuthRule;
use think\facade\Db;

/**
 * 权限服务（RBAC：用户 → 用户组 → 权限菜单）
 *
 * 权限唯一来源：zsk_auth_group（用户组）的 rules 字段（权限菜单 ID 集合）。
 * 用户可同时归属多个用户组（zsk_auth_group_access），最终权限为所有所属组权限的并集。
 * 若所属任一用户组的 rules='*'，则该用户为超级管理员，拥有全部权限。
 */
class Permission
{
    /** 超级管理员标记 */
    public const ALL = '*';

    /** 请求内缓存 */
    protected static array $cache = [];

    /**
     * 清除缓存（授权变更后调用）
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * 所有启用状态的规则（含菜单与权限点）
     */
    public static function rules(): array
    {
        if (!isset(self::$cache['rules'])) {
            self::$cache['rules'] = Db::name('auth_rule')
                ->where('status', 1)
                ->whereNull('delete_time')
                ->order('sort', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }

        return self::$cache['rules'];
    }

    /**
     * 所有规则（含已禁用），仅用于后台管理，
     * 否则一旦禁用就会从列表消失、再也找不到入口重新启用
     */
    public static function allRules(): array
    {
        if (!isset(self::$cache['allRules'])) {
            self::$cache['allRules'] = Db::name('auth_rule')
                ->whereNull('delete_time')
                ->order('sort', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }

        return self::$cache['allRules'];
    }

    /**
     * 所有权限点标识（type=2）
     */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::rules() as $rule) {
            if ((int) $rule['type'] === AuthRule::TYPE_ACTION && $rule['name'] !== '') {
                $keys[] = (string) $rule['name'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * 完整权限树（菜单 + 其下权限点），仅含启用项，用于后台勾选授权
     */
    public static function tree(): array
    {
        return self::buildTree(self::rules(), 0);
    }

    /**
     * 管理用权限树：包含已禁用的菜单与权限点
     */
    public static function manageTree(): array
    {
        return self::buildTree(self::allRules(), 0);
    }

    /**
     * 菜单树（仅 type=1），并按用户权限过滤
     */
    public static function menusFor(?array $admin): array
    {
        $owned = self::forAdmin($admin);
        $isAll = in_array(self::ALL, $owned, true);

        // 用完整规则树过滤，这样「菜单下任意一个权限点被授权」即可保留该菜单
        $tree = self::buildTree(self::rules(), 0);

        return self::filterMenuTree($tree, $owned, $isAll);
    }

    /**
     * 用户所属的用户组 ID（主组 + 附加组）
     */
    public static function groupIdsOf(?array $admin): array
    {
        if (!$admin) {
            return [];
        }

        $ids = array_merge(
            [(int) ($admin['group_id'] ?? 0)],
            array_map('intval', Db::name('auth_group_access')
                ->where('uid', (int) $admin['id'])
                ->column('group_id'))
        );

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * 是否超级管理员：所属任一用户组的 rules='*'
     */
    public static function isSuper(?array $admin): bool
    {
        return self::groupsAreSuper(self::groupIdsOf($admin));
    }

    /**
     * 给定的用户组中是否存在「全部权限」组
     */
    public static function groupsAreSuper(array $groupIds): bool
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (!$groupIds) {
            return false;
        }

        return Db::name('auth_group')
                ->whereIn('id', $groupIds)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->where('rules', self::ALL)
                ->count() > 0;
    }

    /**
     * 某管理员拥有的权限点 key 列表；超管返回 ['*']
     */
    public static function forAdmin(?array $admin): array
    {
        if (!$admin) {
            return [];
        }
        // 内置初始超级管理员（id=1）始终拥有全部权限：
        // 即使授权配置被误改，也保证至少有一个账号能进入系统
        if ((int) ($admin['id'] ?? 0) === 1) {
            return [self::ALL];
        }
        if (self::isSuper($admin)) {
            return [self::ALL];
        }

        $groupIds = self::groupIdsOf($admin);
        $ruleIds  = [];
        foreach ($groupIds as $gid) {
            $rules = Db::name('auth_group')
                ->where('id', $gid)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->value('rules');
            if (!$rules) {
                continue;
            }
            $ruleIds = array_merge($ruleIds, array_map('intval', explode(',', (string) $rules)));
        }

        return self::keysByRuleIds($ruleIds);
    }

    /**
     * 根据规则 ID（可含父级菜单 ID）展开出所有权限点 key
     */
    public static function keysByRuleIds(array $ids): array
    {
        $all   = self::rules();
        $byId  = array_column($all, null, 'id');
        $owned = [];

        $walk = function (int $id) use (&$walk, &$owned, $all): void {
            $owned[$id] = true;
            foreach ($all as $rule) {
                if ((int) $rule['pid'] === $id) {
                    $walk((int) $rule['id']);
                }
            }
        };

        foreach ($ids as $id) {
            if ($id > 0 && isset($byId[$id])) {
                $walk($id);
            }
        }

        $keys = [];
        foreach ($all as $rule) {
            if (
                (int) $rule['type'] === AuthRule::TYPE_ACTION
                && $rule['name'] !== ''
                && isset($owned[(int) $rule['id']])
            ) {
                $keys[] = (string) $rule['name'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * 判断指定管理员是否拥有某权限
     */
    public static function has(?array $admin, string $key): bool
    {
        if (!$admin) {
            return false;
        }

        $owned = self::forAdmin($admin);

        return in_array(self::ALL, $owned, true) || in_array($key, $owned, true);
    }

    /**
     * 判断当前登录管理员是否拥有某权限
     */
    public static function can(string $key): bool
    {
        return self::has(Context::admin(), $key);
    }

    /**
     * 给前端返回的权限列表：超管为 ['*']
     */
    public static function responseFor(?array $admin): array
    {
        return self::forAdmin($admin);
    }

    /**
     * 根据请求方法与路径匹配所需的权限点
     */
    public static function matchRoute(string $method, string $path): ?string
    {
        $rules = (array) config('auth_route', []);
        $path  = '/' . trim(str_replace(['/index.php'], '', $path), '/');

        foreach ($rules as $rule) {
            [$rMethod, $rPath, $key] = $rule;
            if (strtoupper($rMethod) !== strtoupper($method)) {
                continue;
            }
            $pattern = '#^' . preg_replace('#/:([a-zA-Z_][a-zA-Z0-9_]*)#', '/([^/]+)', '/' . trim($rPath, '/')) . '$#';
            if (preg_match($pattern, $path)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * 递归构建树
     */
    protected static function buildTree(array $nodes, int $pid = 0): array
    {
        $branch = [];
        foreach ($nodes as $node) {
            if ((int) $node['pid'] !== $pid) {
                continue;
            }
            $children = self::buildTree($nodes, (int) $node['id']);
            if ($children) {
                $node['children'] = $children;
            }
            $branch[] = $node;
        }

        return $branch;
    }

    /**
     * 按权限过滤菜单树：只保留菜单节点，
     * 菜单自身被授权 或 其下任意权限点被授权时保留
     */
    protected static function filterMenuTree(array $tree, array $owned, bool $isAll): array
    {
        $result = [];
        foreach ($tree as $node) {
            if ((int) $node['type'] !== AuthRule::TYPE_MENU) {
                continue;
            }

            $children       = !empty($node['children']) ? self::filterMenuTree($node['children'], $owned, $isAll) : [];
            $selfOwned      = $node['name'] !== '' && in_array($node['name'], $owned, true);
            $hasOwnedAction = self::hasOwnedAction($node, $owned);

            if (!$isAll && !$selfOwned && !$hasOwnedAction) {
                continue;
            }

            if ($children) {
                $node['children'] = $children;
            } else {
                unset($node['children']);
            }
            $result[] = $node;
        }

        return $result;
    }

    /**
     * 判断节点自身或其后代中是否有被授权的权限点
     */
    protected static function hasOwnedAction(array $node, array $owned): bool
    {
        if (!empty($node['children'])) {
            foreach ($node['children'] as $child) {
                if (self::hasOwnedAction($child, $owned)) {
                    return true;
                }
            }
        }

        return (int) ($node['type'] ?? AuthRule::TYPE_MENU) === AuthRule::TYPE_ACTION
            && $node['name'] !== ''
            && in_array($node['name'], $owned, true);
    }
}
