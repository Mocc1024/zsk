<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 内容导出（M2.6）
 *
 * 导出：CSV（Excel 可直接打开）、ZIP（多篇 Markdown）
 *
 * 说明：导入能力（CSV / Markdown / ZIP 解析）已下线，故此处只保留导出所需的成员。
 */
class ContentImportExport
{
    /** 导出字段表头 */
    public const CSV_HEADERS = [
        'id', 'title', 'category', 'author', 'tags', 'summary', 'content', 'status', 'views', 'create_time', 'publish_time',
    ];

    /**
     * 构建 CSV 文本（带 BOM，Excel 打开不乱码）
     */
    public static function buildCsv(array $contents): string
    {
        $handle = fopen('php://temp', 'rb+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::CSV_HEADERS);

        foreach ($contents as $content) {
            fputcsv($handle, [
                $content['id'] ?? '',
                $content['title'] ?? '',
                $content['category_name'] ?? '',
                $content['author'] ?? '',
                implode(',', $content['tags'] ?? []),
                $content['summary'] ?? '',
                $content['content'] ?? '',
                ($content['status'] ?? 0) == 1 ? '已发布' : '草稿',
                $content['views'] ?? 0,
                $content['create_time'] ?? '',
                $content['publish_time'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * 构建单篇 Markdown 文本
     */
    public static function buildMarkdown(array $content): string
    {
        $meta = [
            'title'    => $content['title'] ?? '',
            'category' => $content['category_name'] ?? '',
            'author'   => $content['author'] ?? '',
            'tags'     => implode(',', $content['tags'] ?? []),
            'summary'  => $content['summary'] ?? '',
        ];

        $text = "---\n";
        foreach ($meta as $key => $value) {
            if ($value === '') {
                continue;
            }
            $text .= $key . ': ' . $value . "\n";
        }
        $text .= "---\n\n";

        $text .= '# ' . ($content['title'] ?? '') . "\n\n";
        $text .= self::htmlToMarkdown((string) ($content['content'] ?? '')) . "\n";

        return $text;
    }

    /**
     * 极简 HTML → Markdown（覆盖编辑器产出的标签）
     */
    public static function htmlToMarkdown(string $html): string
    {
        $html = preg_replace('/<(script|style)[\s\S]*?<\/\1>/i', '', $html) ?? '';
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\/(h[1-6]|p|blockquote|li|pre|div)>/i', "</$1>\n", $html);
        $html = (string) preg_replace_callback(
            '/<h([1-6])>([\s\S]*?)<\/h\1>/i',
            static fn (array $m): string => str_repeat('#', (int) $m[1]) . ' ' . trim($m[2]) . "\n",
            $html
        );
        $html = (string) preg_replace('/<strong>([\s\S]*?)<\/strong>/i', '**$1**', $html);
        $html = (string) preg_replace('/<(em|i)>([\s\S]*?)<\/\1>/i', '*$2*', $html);
        $html = (string) preg_replace('/<a[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/i', '[$2]($1)', $html);
        $html = (string) preg_replace('/<img[^>]*src="([^"]*)"[^>]*alt="([^"]*)"[^>]*>/i', '![$2]($1)', $html);
        $html = (string) preg_replace('/<li>([\s\S]*?)<\/li>/i', "- $1", $html);
        $html = (string) preg_replace('/<pre><code[^>]*>([\s\S]*?)<\/code><\/pre>/i', "```\n$1\n```\n", $html);
        $html = (string) preg_replace('/<blockquote>([\s\S]*?)<\/blockquote>/i', "> $1", $html);

        $text = trim((string) strip_tags($html));
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return $text;
    }
}
