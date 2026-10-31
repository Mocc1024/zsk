<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 站点配置（zsk_setting）
 *
 * 只放「运营要在后台改的开关与阈值」，技术参数仍在 config/*.php。
 *
 * 全部走缓存：这些值在列表/详情等高频路径上被读取，
 * 每次都查库没必要；后台改值时调用 set() 主动失效缓存。
 *
 * 缓存只是加速，不是必需：读写全部包了 try/catch，
 * 缓存不可用时退化成直接查库 —— 配置读取不能因为缓存挂了就整个 500。
 */
class Setting
{
    private const CACHE_KEY = 'settings';

    private const CACHE_TTL = 3600;

    /**
     * 取一个配置，缺省返回 $default
     */
    public static function get(string $key, $default = '')
    {
        $group = str_contains($key, '.') ? explode('.', $key, 2)[0] : 'site';
        $name  = str_contains($key, '.') ? explode('.', $key, 2)[1] : $key;

        $all = self::all();

        return $all[$group][$name] ?? $default;
    }

    /**
     * 取整数配置
     */
    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    /**
     * 全量配置，按 group 分组
     */
    public static function all(): array
    {
        $cached = self::cacheGet();
        if ($cached !== null) {
            return $cached;
        }

        $list = Db::name('setting')->field('group, key, value')->select()->toArray();

        $grouped = [];
        foreach ($list as $row) {
            $grouped[$row['group']][$row['key']] = $row['value'];
        }

        self::cacheSet($grouped);

        return $grouped;
    }

    /**
     * 写配置（后台用），写完立刻失效缓存
     */
    public static function set(string $group, string $key, string $value): void
    {
        $exists = Db::name('setting')->where('group', $group)->where('key', $key)->find();

        if ($exists) {
            $exists = is_array($exists) ? $exists : $exists->toArray();
            Db::name('setting')->where('id', (int) $exists['id'])->update([
                'value'       => $value,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::name('setting')->insert([
                'group'       => $group,
                'key'         => $key,
                'value'       => $value,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        self::cacheClear();
    }

    /** 缓存不可用（未装扩展、服务未启动）时返回 null，由调用方查库 */
    private static function cacheGet(): ?array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            return is_array($cached) ? $cached : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cacheSet(array $grouped): void
    {
        try {
            Cache::set(self::CACHE_KEY, $grouped, self::CACHE_TTL);
        } catch (\Throwable) {
            // 写不进缓存不影响正确性，下次请求直接查库
        }
    }

    private static function cacheClear(): void
    {
        try {
            Cache::delete(self::CACHE_KEY);
        } catch (\Throwable) {
            // 清不掉最多是配置晚一会儿生效
        }
    }
}
