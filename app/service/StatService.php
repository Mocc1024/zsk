<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 内容浏览统计（PV / UV）
 *
 * 两个粒度：
 *   · zsk_content.views      累计总浏览量，列表排序要用
 *   · zsk_content_stat       日粒度，(content_id, date) 唯一
 *
 * UV 去重：按「当日 + IP + UA 哈希」放进 Redis SET，sAdd 返回 1 才算新访客。
 * 用 SET 而不是字符串计数，是因为要判断「这个访客今天来过没有」，
 * 且集合会在两天后自动过期，不需要额外的清理任务。
 *
 * Redis 不可用时只统计 PV、不增长 UV：
 * 宁可少算也不能报错——统计不是主流程，但它抛异常会把详情页整个拖垮。
 */
class StatService
{
    /** UV 集合的存活时间（秒）：跨天边界留一点余量 */
    private const UV_TTL = 172800;

    /**
     * 记录一次浏览
     */
    public static function recordView(int $contentId, string $ip, string $userAgent): void
    {
        if ($contentId <= 0) {
            return;
        }

        $date     = date('Y-m-d');
        $visitor  = hash('sha256', $date . '|' . $ip . '|' . $userAgent);
        $isNewUv  = self::markVisitor($contentId, $date, $visitor);

        Db::transaction(function () use ($contentId, $date, $isNewUv) {
            $now  = date('Y-m-d H:i:s');
            $stat = Db::name('content_stat')
                ->where('content_id', $contentId)
                ->where('date', $date)
                ->find();

            if ($stat) {
                $stat = is_array($stat) ? $stat : $stat->toArray();
                Db::name('content_stat')->where('id', (int) $stat['id'])->update([
                    'pv'          => (int) $stat['pv'] + 1,
                    'uv'          => (int) $stat['uv'] + ($isNewUv ? 1 : 0),
                    'update_time' => $now,
                ]);
            } else {
                Db::name('content_stat')->insert([
                    'content_id'  => $contentId,
                    'date'        => $date,
                    'pv'          => 1,
                    'uv'          => $isNewUv ? 1 : 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            Db::name('content')->where('id', $contentId)->inc('views')->update();
        });
    }

    /**
     * 标记访客，返回是否为当日新访客
     */
    private static function markVisitor(int $contentId, string $date, string $visitor): bool
    {
        try {
            $redis = Cache::store('redis')->handler();
            if (!is_object($redis) || !method_exists($redis, 'sAdd')) {
                return false;
            }

            $key   = 'content_uv:' . $contentId . ':' . $date;
            $added = (int) $redis->sAdd($key, $visitor);
            $redis->expire($key, self::UV_TTL);

            return $added === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
