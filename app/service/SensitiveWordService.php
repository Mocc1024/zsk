<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 敏感词过滤
 *
 * 词库在 zsk_sensitive_word 表里、后台可维护（3.13 要求），
 * 所以不能硬编码在代码里——改一个词就要发一次版。
 *
 * 全量词库每次请求都查库太浪费，这里缓存在 Redis 里，
 * 后台改词后调用 refresh() 主动失效（M3.7 后台会接上）。
 *
 * 处理级别：1 仅提醒  2 替换  3 拦截并转人工审核
 */
class SensitiveWordService
{
    private const CACHE_KEY = 'sensitive_words';

    private const CACHE_TTL = 3600;

    /** 级别 */
    public const LEVEL_NOTICE = 1;
    public const LEVEL_REPLACE = 2;
    public const LEVEL_BLOCK = 3;

    /**
     * 过滤一段文本
     *
     * @return array ['text' => 处理后文本, 'blocked' => 是否需要拦截, 'hits' => 命中的词]
     */
    public static function filter(string $text): array
    {
        $words = self::words();
        if (!$words) {
            return ['text' => $text, 'blocked' => false, 'hits' => []];
        }

        $hits    = [];
        $blocked = false;

        foreach ($words as $item) {
            $word = (string) $item['word'];
            if ($word === '' || !str_contains($text, $word)) {
                continue;
            }

            $hits[] = $word;
            $level  = (int) $item['level'];

            if ($level === self::LEVEL_BLOCK) {
                $blocked = true;
                continue;
            }

            if ($level === self::LEVEL_REPLACE) {
                $text = str_replace($word, (string) $item['replacement'], $text);
            }
            // LEVEL_NOTICE：只记录，不动文本
        }

        return ['text' => $text, 'blocked' => $blocked, 'hits' => array_values(array_unique($hits))];
    }

    /**
     * 取全部启用词条（带缓存）
     */
    public static function words(): array
    {
        $cached = self::cacheGet();
        if ($cached !== null) {
            return $cached;
        }

        $list = Db::name('sensitive_word')
            ->where('status', 1)
            ->field('word, level, replacement')
            ->select()
            ->toArray();

        self::cacheSet($list);

        return $list;
    }

    /**
     * 后台改词后调用，让下一次请求重新读库
     */
    public static function refresh(): void
    {
        self::cacheClear();
    }

    /** 与 Setting 同理：缓存只是加速，不可用就直接查库，不能把评论发布拖垮 */
    private static function cacheGet(): ?array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            return is_array($cached) ? $cached : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cacheSet(array $list): void
    {
        try {
            Cache::set(self::CACHE_KEY, $list, self::CACHE_TTL);
        } catch (\Throwable) {
        }
    }

    private static function cacheClear(): void
    {
        try {
            Cache::delete(self::CACHE_KEY);
        } catch (\Throwable) {
        }
    }
}
