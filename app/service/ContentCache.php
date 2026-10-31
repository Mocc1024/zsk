<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;

/**
 * 前台内容缓存
 *
 * 用标签（tag）而不是散落的键：内容一改要失效的可能是一整组列表
 * （首页、分类页、搜索页都受影响），按 tag 一次性清掉最省事，
 * 也不用维护「哪些键与哪些内容相关」的映射表。
 *
 * TTL 刻意设短：最迟 60 秒后自动生效，即使某处漏了主动失效，
 * 也不会出现「后台改了前台永远不变」这种最难排查的问题。
 * 需要立即生效时执行 php scripts/clear_content_cache.php。
 */
class ContentCache
{
    private const TAG = 'content';

    /** 列表缓存时长（秒） */
    public const TTL_LIST = 60;

    /**
     * 取缓存，没有则回调生成并写入
     */
    public static function remember(string $key, callable $factory, int $ttl = self::TTL_LIST)
    {
        return Cache::tag(self::TAG)->remember(self::TAG . ':' . $key, $factory, $ttl);
    }

    /**
     * 清除全部内容缓存
     */
    public static function flush(): void
    {
        Cache::tag(self::TAG)->clear();
    }
}
