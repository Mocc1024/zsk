<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ContentReader;
use think\facade\Db;

/**
 * SEO：站点地图
 *
 * 动态生成而不是生成静态文件：
 * 内容上下架是高频操作，静态文件要么每次改动都重新生成（要挂钩子），
 * 要么就长期不准。前台流量下这个查询很轻，动态生成更省心。
 * 数据量上来后再改为定时任务生成静态文件。
 */
class SeoController extends BaseController
{
    /**
     * 站点地图
     */
    public function sitemap()
    {
        $base = self::siteUrl();
        $urls = [];

        $urls[] = self::node($base . '/', '1.0');

        // 分类
        $categories = Db::name('category')
            ->whereNull('delete_time')
            ->where('status', 1)
            ->field('id, update_time')
            ->select()
            ->toArray();

        foreach ($categories as $cat) {
            $urls[] = self::node($base . '/category/' . $cat['id'], '0.6', $cat['update_time']);
        }

        // 已发布内容
        $contents = ContentReader::scope()
            ->field('c.id, c.update_time, c.publish_time')
            ->select()
            ->toArray();

        foreach ($contents as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $urls[] = self::node($base . '/content/' . $row['id'], '0.8', $row['update_time'] ?: $row['publish_time']);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL
            . implode(PHP_EOL, $urls) . PHP_EOL
            . '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private static function node(string $loc, string $priority, $lastmod = null): string
    {
        $lastmod = $lastmod ? date('Y-m-d', strtotime((string) $lastmod)) : date('Y-m-d');

        return '  <url>'
            . '<loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>'
            . '<lastmod>' . $lastmod . '</lastmod>'
            . '<changefreq>daily</changefreq>'
            . '<priority>' . $priority . '</priority>'
            . '</url>';
    }

    /**
     * 站点根地址：优先用环境变量，取不到就用当前请求的域名
     */
    private static function siteUrl(): string
    {
        $configured = (string) env('SITE_URL', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = request()->isSsl() ? 'https' : 'http';

        return $scheme . '://' . request()->host();
    }
}
