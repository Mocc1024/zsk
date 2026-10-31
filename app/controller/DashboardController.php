<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Admin;
use app\model\Category;
use app\model\Content;
use app\model\OperationLog;
use think\facade\Db;

/**
 * 数据看板
 *
 * 信息层级：待处理 → 核心指标（内容 / 用户 / 互动）→ 趋势 → 分布 → 明细。
 *
 * 为什么要把「待处理」放最上面：管理员打开后台第一件事是「有什么要我处理」，
 * 而不是「有多少条内容」。原来只有内容维度的数字，既看不到站点运营状况，
 * 也看不到待办，看完还得自己去找审核入口。
 */
class DashboardController extends BaseController
{
    /**
     * 评论待审状态
     *
     * 与 CommentController::STATUS_PENDING 同义，但那边是 private 且状态常量
     * 没有下沉到模型层，这里只能自己定义一份。改评论状态值时两处都要动。
     */
    private const COMMENT_PENDING = 0;

    /** 举报待处理状态（与 ReportManageController::STATUS_PENDING 同义） */
    private const REPORT_PENDING = 0;

    public function index()
    {
        $this->checkPermission('dashboard.view');

        // =========================================================
        // 内容
        // =========================================================
        $total = (int) Content::whereNull('delete_time')->count();

        $statusRows = Db::name('content')
            ->whereNull('delete_time')
            ->group('status')
            ->column('COUNT(*)', 'status');

        $published = (int) ($statusRows[Content::STATUS_PUBLISHED] ?? 0);
        $draft     = (int) ($statusRows[Content::STATUS_DRAFT] ?? 0);
        $offline   = (int) ($statusRows[Content::STATUS_OFFLINE] ?? 0);
        $views     = (int) Db::name('content')->whereNull('delete_time')->sum('views');

        $statusMap = Content::statusMap();
        $statusDistribution = [];
        foreach ($statusMap as $value => $label) {
            $statusDistribution[] = [
                'name'  => $label,
                'value' => (int) ($statusRows[$value] ?? 0),
            ];
        }

        $categoryDistribution = Db::name('content')
            ->alias('c')
            ->field('cat.name as name, COUNT(c.id) as value')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->group('c.category_id')
            ->order('value', 'desc')
            ->select()
            ->toArray();

        // =========================================================
        // 用户
        // =========================================================
        // 已注销（delete_time 非空）的不计入：注销账号既不能登录也不该出现在运营口径里
        $userTotal = (int) Db::name('user')->whereNull('delete_time')->count();
        $todayUser = (int) Db::name('user')
            ->whereNull('delete_time')
            ->whereTime('create_time', 'today')
            ->count();

        // =========================================================
        // 互动
        // =========================================================
        $commentTotal  = (int) Db::name('comment')->whereNull('delete_time')->count();
        $favoriteTotal = (int) Db::name('user_favorite')->count();
        $likeTotal     = (int) Db::name('comment_like')->count();

        // =========================================================
        // 待处理（管理员最关心的入口）
        // =========================================================
        $todo = [
            [
                'key'   => 'comment_pending',
                'title' => '待审评论',
                'value' => (int) Db::name('comment')
                    ->whereNull('delete_time')
                    ->where('status', self::COMMENT_PENDING)
                    ->count(),
                'desc' => '等待审核通过后才会公开展示',
                'link' => '/interact?tab=comment',
            ],
            [
                'key'   => 'report_pending',
                'title' => '待处理举报',
                'value' => (int) Db::name('user_report')
                    ->where('status', self::REPORT_PENDING)
                    ->count(),
                'desc' => '用户举报，需运营判断处理',
                'link' => '/interact?tab=report',
            ],
            [
                'key'   => 'draft',
                'title' => '草稿内容',
                'value' => $draft,
                'desc'  => '尚未发布，前台不可见',
                // 带上状态参数，让列表页直接筛出草稿，而不是落到列表再自己选一次
                'link'  => '/content?status=' . Content::STATUS_DRAFT,
            ],
        ];

        // =========================================================
        // 核心指标（分组：内容 / 用户 / 互动）
        // =========================================================
        $groups = [
            [
                'key'   => 'content',
                'title' => '内容',
                'items' => [
                    ['key' => 'content', 'title' => '内容总数', 'value' => $total, 'suffix' => '条', 'desc' => '不含回收站'],
                    ['key' => 'published', 'title' => '已发布', 'value' => $published, 'suffix' => '条', 'desc' => '当前对外展示中'],
                    ['key' => 'views', 'title' => '累计浏览量', 'value' => $views, 'suffix' => '次', 'desc' => '全部内容浏览总量'],
                ],
            ],
            [
                'key'   => 'user',
                'title' => '用户',
                'items' => [
                    ['key' => 'users', 'title' => '注册用户', 'value' => $userTotal, 'suffix' => '人', 'desc' => '不含已注销'],
                    ['key' => 'todayUser', 'title' => '今日新增', 'value' => $todayUser, 'suffix' => '人', 'desc' => '今天注册的用户'],
                ],
            ],
            [
                'key'   => 'interact',
                'title' => '互动',
                'items' => [
                    ['key' => 'comments', 'title' => '评论总数', 'value' => $commentTotal, 'suffix' => '条', 'desc' => '不含已删除'],
                    ['key' => 'favorites', 'title' => '收藏总数', 'value' => $favoriteTotal, 'suffix' => '次', 'desc' => '用户收藏累计'],
                    ['key' => 'likes', 'title' => '评论点赞', 'value' => $likeTotal, 'suffix' => '次', 'desc' => '评论获赞累计'],
                ],
            ],
        ];

        // =========================================================
        // 近 14 天趋势：内容新增 + 用户新增
        // =========================================================
        $days         = 14;
        $contentTrend = $this->dailyCounts('content', 'create_time', $days);
        $userTrend    = $this->dailyCounts('user', 'create_time', $days);

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day     = date('Y-m-d', strtotime('-' . $i . ' days'));
            $trend[] = [
                'date'    => $day,
                'label'   => date('m-d', strtotime($day)),
                'content' => (int) ($contentTrend[$day] ?? 0),
                'user'    => (int) ($userTrend[$day] ?? 0),
            ];
        }

        // =========================================================
        // 用户等级分布
        // =========================================================
        $levelRows = Db::name('user')
            ->whereNull('delete_time')
            ->group('level')
            ->column('COUNT(*)', 'level');
        $levelNames = Db::name('user_level')
            ->order('level', 'asc')
            ->column('name', 'level');

        $levelDistribution = [];
        foreach ($levelNames as $level => $name) {
            $levelDistribution[] = [
                'name'  => 'Lv' . $level . ' ' . $name,
                'value' => (int) ($levelRows[$level] ?? 0),
            ];
        }

        // =========================================================
        // 明细
        // =========================================================
        $hotContents = Db::name('content')
            ->alias('c')
            ->field('c.id, c.title, c.views, c.status, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->order('c.views', 'desc')
            ->limit(8)
            ->select()
            ->toArray();

        $latestContents = Db::name('content')
            ->alias('c')
            ->field('c.id, c.title, c.status, c.author, c.create_time, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->order('c.id', 'desc')
            ->limit(6)
            ->select()
            ->toArray();

        $latestUsers = Db::name('user')
            ->field('id, username, nickname, level, create_time')
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->limit(6)
            ->select()
            ->toArray();

        $latestLogs = OperationLog::order('id', 'desc')->limit(8)->select()->toArray();

        return $this->success([
            'todo'                 => $todo,
            'groups'               => $groups,
            'statusDistribution'   => $statusDistribution,
            'categoryDistribution' => $categoryDistribution,
            'levelDistribution'    => $levelDistribution,
            'trend'                => $trend,
            'hotContents'          => $hotContents,
            'latestContents'       => $latestContents,
            'latestUsers'          => $latestUsers,
            'latestLogs'           => $latestLogs,
            // 配置型数量：不做成主指标卡，但后台偶尔要看，挂在末尾
            'meta'                 => [
                'category' => (int) Category::whereNull('delete_time')->count(),
                'admin'    => (int) Admin::whereNull('delete_time')->count(),
                'offline'  => $offline,
            ],
        ]);
    }

    /**
     * 按天统计新增量
     *
     * @param bool $excludeDeleted 是否排除软删除行（内容回收站、已注销用户）
     * @return array ['2026-10-31' => 3, ...]
     */
    private function dailyCounts(string $table, string $field, int $days, bool $excludeDeleted = true): array
    {
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $query = Db::name($table)->where($field, '>=', $start);
        if ($excludeDeleted) {
            $query->whereNull('delete_time');
        }

        // $field 是内部传入的固定字段名，不是用户输入，可直接拼
        $rows = $query
            ->field("DATE_FORMAT({$field}, '%Y-%m-%d') as day, COUNT(*) as total")
            ->group("DATE_FORMAT({$field}, '%Y-%m-%d')")
            ->select()
            ->toArray();

        return array_column($rows, 'total', 'day');
    }
}
