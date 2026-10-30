<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 标签服务
 *
 * 内容标签从「逗号分隔字符串」升级为「标签表 + 关联表」（ADR-005）。
 * 为兼容第一阶段已上线的列表搜索与展示，zsk_content.tags 仍保留逗号串作为冗余字段，
 * 唯一权威来源是 zsk_content_tag 关联表。
 */
class TagService
{
    /** 单篇内容最多标签数 */
    public const MAX_PER_CONTENT = 10;

    /** 标签名最大长度 */
    public const MAX_LENGTH = 20;

    /**
     * 同步某篇内容的标签
     *
     * @param int   $contentId 内容 ID
     * @param array $names     标签名数组
     */
    public static function syncForContent(int $contentId, array $names): void
    {
        if ($contentId <= 0) {
            return;
        }

        $normalized = self::normalize($names);

        $newIds = [];
        foreach ($normalized as $name) {
            $newIds[] = self::ensure($name);
        }

        $oldIds = array_map('intval', Db::name('content_tag')
            ->where('content_id', $contentId)
            ->column('tag_id'));

        $add = array_diff($newIds, $oldIds);
        $del = array_diff($oldIds, $newIds);

        foreach ($add as $tagId) {
            Db::name('content_tag')->insert([
                'content_id' => $contentId,
                'tag_id'     => (int) $tagId,
            ]);
        }

        if ($del) {
            Db::name('content_tag')
                ->where('content_id', $contentId)
                ->whereIn('tag_id', $del)
                ->delete();
        }

        foreach ($add as $tagId) {
            Db::name('tag')->where('id', (int) $tagId)->inc('count')->update();
        }
        foreach ($del as $tagId) {
            Db::name('tag')->where('id', (int) $tagId)->dec('count')->update();
        }

        if ($add || $del) {
            Db::name('tag')->where('count', '<', 0)->update(['count' => 0]);
        }
    }

    /**
     * 内容彻底删除时清理标签关系
     */
    public static function clearForContent(int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }

        $ids = array_map('intval', Db::name('content_tag')
            ->where('content_id', $contentId)
            ->column('tag_id'));

        foreach ($ids as $tagId) {
            Db::name('tag')->where('id', $tagId)->dec('count')->update();
        }

        Db::name('content_tag')->where('content_id', $contentId)->delete();
        Db::name('tag')->where('count', '<', 0)->update(['count' => 0]);
    }

    /**
     * 某篇内容的标签名
     */
    public static function namesOf(int $contentId): array
    {
        return array_map('strval', Db::name('content_tag')
            ->alias('ct')
            ->join('tag t', 'ct.tag_id = t.id', 'LEFT')
            ->where('ct.content_id', $contentId)
            ->order('t.sort', 'desc')
            ->order('t.id', 'asc')
            ->column('t.name'));
    }

    /**
     * 批量填充多篇内容的标签（列表页用，避免 N+1）
     *
     * @param array $contentIds 内容 ID 列表
     * @return array 内容 ID => 标签名数组
     */
    public static function namesForContents(array $contentIds): array
    {
        $result = [];
        foreach ($contentIds as $id) {
            $result[(int) $id] = [];
        }

        $contentIds = array_values(array_filter(array_map('intval', $contentIds)));
        if (!$contentIds) {
            return $result;
        }

        $rows = Db::name('content_tag')
            ->alias('ct')
            ->field('ct.content_id, t.name')
            ->join('tag t', 'ct.tag_id = t.id', 'LEFT')
            ->whereIn('ct.content_id', $contentIds)
            ->order('t.sort', 'desc')
            ->order('t.id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $result[(int) $row['content_id']][] = (string) $row['name'];
        }

        return $result;
    }

    /**
     * 规范化标签名：去重、去空、限长、限量
     */
    public static function normalize(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $name = mb_substr($name, 0, self::MAX_LENGTH);
            if (!in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        return array_slice($clean, 0, self::MAX_PER_CONTENT);
    }

    /**
     * 标签名转存储串（写入 zsk_content.tags）
     */
    public static function namesToText(array $names): string
    {
        return implode(',', self::normalize($names));
    }

    /**
     * 存储串转标签名数组
     */
    public static function textToNames(string $text): array
    {
        return self::normalize(explode(',', $text));
    }

    /**
     * 取标签 ID，不存在则创建
     */
    private static function ensure(string $name): int
    {
        $id = (int) Db::name('tag')->where('name', $name)->whereNull('delete_time')->value('id');
        if ($id > 0) {
            return $id;
        }

        $now = date('Y-m-d H:i:s');

        return (int) Db::name('tag')->insertGetId([
            'name'        => $name,
            'slug'        => self::slug($name),
            'count'       => 0,
            'sort'        => 0,
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /**
     * 生成 slug：有 ASCII 字符时用其转写，纯中文时退回哈希（环境无拼音库）
     */
    private static function slug(string $name): string
    {
        $ascii = trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-');
        if ($ascii !== '') {
            return strtolower($ascii);
        }

        return 'tag-' . substr(md5($name), 0, 10);
    }
}
