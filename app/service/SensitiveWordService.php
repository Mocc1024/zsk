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
     * 只检测不替换：用于发表评论时判断是否命中拦截级。
     *
     * 原文完整入库——替换级（2 级）不做物理改写，由 display() 在
     * 前台展示时替换，库里始终保留原词（后台审核看到的是原文）。
     *
     * @return array ['blocked' => 是否需要拦截, 'hits' => 命中的词]
     */
    public static function detect(string $text): array
    {
        $words   = self::words();
        $hits    = [];
        $blocked = false;

        foreach ($words as $item) {
            $word = (string) $item['word'];
            if ($word === '' || !str_contains($text, $word)) {
                continue;
            }

            $hits[] = $word;
            if ((int) $item['level'] === self::LEVEL_BLOCK) {
                $blocked = true;
            }
        }

        return ['blocked' => $blocked, 'hits' => array_values(array_unique($hits))];
    }

    /**
     * 展示层替换：把替换级（2 级）命中的词换成替换文本，只动输出不动库。
     *
     * 拦截级（3 级）命中的评论会被转待审，正常不该出现在前台；
     * 但词库是动态维护的——已通过的评论可能在词库后来加入拦截词，
     * 展示层对 3 级命中同样兜底替换，避免漏网。
     */
    public static function display(string $text): string
    {
        $words = self::words();
        if (!$words) {
            return $text;
        }

        foreach ($words as $item) {
            $word  = (string) $item['word'];
            $level = (int) $item['level'];
            if ($word === '' || ($level !== self::LEVEL_REPLACE && $level !== self::LEVEL_BLOCK)) {
                continue;
            }
            if (!str_contains($text, $word)) {
                continue;
            }
            $text = str_replace($word, (string) $item['replacement'], $text);
        }

        return $text;
    }

    /**
     * 过滤一段文本（已废弃于评论链路：入库不再物理替换，改用 detect + display）
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
