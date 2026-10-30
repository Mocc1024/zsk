<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 附件引用服务
 *
 * 维护「内容 ↔ 附件」的引用关系（zsk_attachment_ref），并据此维护
 * zsk_attachment.ref_count，用于素材库的引用次数展示与删除保护（M2.1）。
 *
 * 关系在内容保存 / 恢复时同步，在内容彻底删除时清理。
 */
class AttachmentService
{
    /**
     * 从封面与正文 HTML 中提取本站附件 URL
     */
    public static function extractUrls(string $html, string $cover = ''): array
    {
        $urls = [];

        if ($cover !== '' && str_starts_with($cover, '/storage/')) {
            $urls[] = $cover;
        }

        if ($html !== '' && preg_match_all('#/storage/[A-Za-z0-9_\-./]+#', $html, $matched)) {
            foreach ($matched[0] as $url) {
                $urls[] = rtrim($url, '.,;:)');
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * 同步某篇内容的附件引用
     *
     * @param int    $contentId 内容 ID
     * @param string $html      正文
     * @param string $cover     封面 URL
     */
    public static function syncForContent(int $contentId, string $html, string $cover = ''): void
    {
        if ($contentId <= 0) {
            return;
        }

        $urls = self::extractUrls($html, $cover);

        $newIds = [];
        if ($urls) {
            $newIds = array_map('intval', Db::name('attachment')
                ->whereIn('url', $urls)
                ->whereNull('delete_time')
                ->column('id'));
        }

        $oldIds = array_map('intval', Db::name('attachment_ref')
            ->where('content_id', $contentId)
            ->column('attachment_id'));

        $add = array_diff($newIds, $oldIds);
        $del = array_diff($oldIds, $newIds);

        foreach ($add as $attachmentId) {
            Db::name('attachment_ref')->insert([
                'content_id'    => $contentId,
                'attachment_id' => (int) $attachmentId,
            ]);
            Db::name('attachment')->where('id', (int) $attachmentId)->inc('ref_count')->update();
        }

        foreach ($del as $attachmentId) {
            Db::name('attachment_ref')
                ->where('content_id', $contentId)
                ->where('attachment_id', (int) $attachmentId)
                ->delete();
            Db::name('attachment')->where('id', (int) $attachmentId)->dec('ref_count')->update();
        }

        if ($add || $del) {
            Db::name('attachment')->where('ref_count', '<', 0)->update(['ref_count' => 0]);
        }
    }

    /**
     * 内容彻底删除时清理其全部引用
     */
    public static function clearForContent(int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }

        $ids = array_map('intval', Db::name('attachment_ref')
            ->where('content_id', $contentId)
            ->column('attachment_id'));

        foreach ($ids as $attachmentId) {
            Db::name('attachment')->where('id', $attachmentId)->dec('ref_count')->update();
        }

        Db::name('attachment_ref')->where('content_id', $contentId)->delete();
        Db::name('attachment')->where('ref_count', '<', 0)->update(['ref_count' => 0]);
    }

    /**
     * 引用了该附件的内容（用于删除拦截提示）
     */
    public static function refContents(int $attachmentId, int $limit = 5): array
    {
        return Db::name('attachment_ref')
            ->alias('r')
            ->field('c.id, c.title')
            ->join('content c', 'r.content_id = c.id', 'LEFT')
            ->where('r.attachment_id', $attachmentId)
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 删除附件：软删除记录，并更新引用计数（文件实体保留，便于误删恢复）
     */
    public static function softDelete(int $attachmentId): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('attachment')->where('id', $attachmentId)->update([
            'delete_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('attachment_ref')->where('attachment_id', $attachmentId)->delete();
    }
}
