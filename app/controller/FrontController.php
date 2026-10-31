<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ContentCache;
use app\service\ContentReader;
use app\service\StatService;
use think\facade\Db;

/**
 * 前台内容接口（免鉴权只读）
 *
 * 与开放 API 的分工见 ADR-011：
 *   /api/front/*  自家前台 SPA 调用，免鉴权
 *   /api/open/*   第三方调用，AppKey 签名
 * 两边共用 ContentReader 的「已发布」口径，但鉴权方式不同，不要合并。
 *
 * 计数约定：浏览量只在 POST /api/front/stat/view 里累加，详情接口本身不计数。
 * 否则前端每打开一次详情页就会「详情接口 + 上报接口」各加一次，统计翻倍。
 */
class FrontController extends BaseController
{
    /**
     * 首页聚合：置顶 + 最新 + 热门 + 分类导航
     */
    public function home()
    {
        // 首页是访问量最大的接口，四个区块一次聚合后整体缓存
        $data = ContentCache::remember('home', function () {
            return [
                'top'        => ContentReader::listForApi(['is_top' => 1, 'sort' => 'latest'], 1, 5)['list'],
                'latest'     => ContentReader::listForApi(['sort' => 'latest'], 1, 10)['list'],
                'hot'        => ContentReader::listForApi(['sort' => 'hot'], 1, 10)['list'],
                'categories' => self::categoryNav(),
            ];
        });

        return $this->success($data);
    }

    /**
     * 分类导航
     *
     * 单独拆出来是因为布局的导航栏每个页面都要用，
     * 不能只塞在首页聚合里——否则非首页拿不到分类。
     */
    public function categories()
    {
        return $this->success(['list' => self::categoryNav()]);
    }

    /**
     * 列表（分类 / 标签 / 分页 / 排序）
     */
    public function contents()
    {
        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        // 缓存键要带上全部筛选条件，否则分类切换会拿到同一份缓存
        $key = 'list:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE) . ":{$page}:{$limit}");

        return $this->success(
            ContentCache::remember($key, fn () => ContentReader::listForApi($params, $page, $limit))
        );
    }

    /**
     * 详情（正文已二次过滤）
     */
    public function content($id = 0)
    {
        $id  = (int) ($id ?: $this->request->param('id'));
        $row = ContentReader::findOne($id);

        if (!$row) {
            return $this->fail('内容不存在或未发布', 404);
        }

        return $this->success(['content' => $row]);
    }

    /**
     * 搜索
     *
     * 高亮交给前端做：后端只返回原文与关键词，前端按关键词切分渲染。
     * 若后端直接返回带 <mark> 的 HTML，等于把用户输入原样拼进标签里，有 XSS 风险。
     */
    public function search()
    {
        $keyword = trim((string) $this->input('keyword', $this->input('q', '')));
        [$page, $limit] = $this->pageInfo();

        $result = ContentReader::searchForApi($keyword, $page, $limit);
        $result['keyword'] = $keyword;

        return $this->success($result);
    }

    /**
     * PV/UV 上报
     */
    public function statView()
    {
        $id = (int) $this->input('id', $this->input('content_id', 0));
        if ($id <= 0) {
            return $this->fail('缺少内容 ID');
        }

        // 只统计对外可见的内容，避免给草稿/下架内容刷量
        $exists = (int) ContentReader::scope()->where('c.id', $id)->count();
        if (!$exists) {
            return $this->fail('内容不存在或未发布', 404);
        }

        StatService::recordView(
            $id,
            (string) $this->request->ip(),
            (string) $this->request->server('HTTP_USER_AGENT', '')
        );

        return $this->success(null, 'ok');
    }

    /**
     * 分类导航（启用 + 内容数）
     */
    private static function categoryNav(): array
    {
        $list = Db::name('category')
            ->whereNull('delete_time')
            ->where('status', 1)
            ->field('id, name, slug, description, sort')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $counts = Db::name('content')
            ->whereNull('delete_time')
            ->where('status', 1)
            ->group('category_id')
            ->column('COUNT(*)', 'category_id');

        foreach ($list as &$item) {
            $item['content_count'] = (int) ($counts[$item['id']] ?? 0);
        }
        unset($item);

        return $list;
    }
}
