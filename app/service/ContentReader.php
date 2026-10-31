<?php
declare (strict_types = 1);

namespace app\service;

use app\model\Content;
use think\facade\Db;

/**
 * 对外可见内容的读取服务
 *
 * 开放 API（/api/open/*）与前台（/api/front/*）共用同一份「已发布」口径，
 * 避免两边各写一套查询、口径逐渐分叉——尤其是「下架内容必须立刻不可见」
 * 这条，只要有第二处查询就可能漏。
 *
 * 已发布的判定：未软删 + status=已发布 + 发布时间已到（或没设发布时间）
 */
class ContentReader
{
    /** 列表字段：不返回正文，正文只给详情接口，列表带上会白白放大响应体 */
    private const LIST_FIELDS = 'c.id, c.category_id, c.title, c.author, c.cover, c.summary,'
        . ' c.views, c.comment_count, c.favorite_count, c.is_top, c.publish_time, c.create_time,'
        . ' cat.name as category_name';

    /**
     * 已发布内容的查询构造器
     */
    public static function scope()
    {
        $now = date('Y-m-d H:i:s');

        return Db::name('content')
            ->alias('c')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->where('c.status', Content::STATUS_PUBLISHED)
            ->where(function ($query) use ($now) {
                $query->whereNull('c.publish_time')
                    ->whereOr('c.publish_time', '<=', $now);
            });
    }

    /**
     * 分页列表
     *
     * @param array $params category_id / tag_id / keyword / sort(latest|hot) / is_top
     */
    public static function listForApi(array $params, int $page, int $limit): array
    {
        $query = self::scope()->field(self::LIST_FIELDS);

        $categoryId = (int) ($params['category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('c.category_id', $categoryId);
        }

        // 标签筛选：子查询里的表名不会自动补前缀，先取 ID 再过滤
        $tagId = (int) ($params['tag_id'] ?? 0);
        if ($tagId > 0) {
            $taggedIds = array_map('intval', Db::name('content_tag')
                ->where('tag_id', $tagId)
                ->column('content_id'));
            $query->whereIn('c.id', $taggedIds ?: [0]);
        }

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('c.title|c.summary|c.author', 'like', '%' . $keyword . '%');
        }

        if (($params['is_top'] ?? '') !== '' && (int) $params['is_top'] === 1) {
            $query->where('c.is_top', 1);
        }

        $query->order('c.is_top', 'desc');
        if ((string) ($params['sort'] ?? '') === 'hot') {
            $query->order('c.views', 'desc');
        }
        $query->order('c.publish_time', 'desc')->order('c.id', 'desc');

        return self::paginate($query, $page, $limit);
    }

    /**
     * 搜索
     *
     * 中文全文索引用的是 ngram 分词（最小 token 2 字），
     * 所以单字关键词搜不到，这里回退到 LIKE —— 宁可慢一点也不能搜不到。
     */
    public static function searchForApi(string $keyword, int $page, int $limit): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['list' => [], 'total' => 0, 'page' => $page, 'pageSize' => $limit];
        }

        $query = self::scope()->field(self::LIST_FIELDS);

        if (mb_strlen($keyword, 'UTF-8') < 2) {
            // 只回退到标题与摘要的 LIKE，不扫正文：
            // 对 mediumtext 做 '%x%' 是全表扫描，数据量上来会打垮接口，
            // 而单字搜索本身是边缘场景。代价是单字只能搜到标题/摘要命中的内容。
            $query->where('c.title|c.summary', 'like', '%' . $keyword . '%');
            $query->order('c.publish_time', 'desc');
        } else {
            $query->whereRaw(
                'MATCH(c.title, c.summary, c.content) AGAINST (? IN BOOLEAN MODE)',
                [$keyword]
            );
            // 按相关度排序：ORDER BY 里没法用占位符，这里用 PDO 转义后拼接
            $escaped = Db::connect()->getPdo()->quote($keyword);
            $query->orderRaw("MATCH(c.title, c.summary, c.content) AGAINST ({$escaped} IN BOOLEAN MODE) DESC");
        }

        return self::paginate($query, $page, $limit);
    }

    /**
     * 详情。正文输出前二次过 HtmlFilter——
     * 库里可能存着历史脏数据（例如过滤器升级前写入的内容），不能信任。
     */
    public static function findOne(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = self::scope()
            ->field('c.*, cat.name as category_name')
            ->where('c.id', $id)
            ->find();

        if (!$row) {
            return null;
        }

        // Db::name()->find() 直接返回数组，模型查询才返回对象，两种都要接得住
        $row = is_array($row) ? $row : $row->toArray();
        $row['content']       = HtmlFilter::clean((string) ($row['content'] ?? ''));
        $row['category_name'] = $row['category_name'] ?? '未分类';
        $row['tags']          = TagService::namesForContents([$id])[$id] ?? [];

        // 详情不给内部字段，避免把运营内部信息带出去
        unset($row['delete_time'], $row['update_time']);

        return $row;
    }

    /**
     * 分页取数 + 补标签
     */
    private static function paginate($query, int $page, int $limit): array
    {
        $total = (int) (clone $query)->count();
        $list  = $query->page($page, $limit)->select()->toArray();

        $tagMap = $list ? TagService::namesForContents(array_column($list, 'id')) : [];
        foreach ($list as &$item) {
            $item['category_name'] = $item['category_name'] ?? '未分类';
            $item['tags']          = $tagMap[(int) $item['id']] ?? [];
        }
        unset($item);

        return [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ];
    }
}
