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
 */
class DashboardController extends BaseController
{
    public function index()
    {
        $this->checkPermission('dashboard.view');

        $total = (int) Content::whereNull('delete_time')->count();

        // 状态分布
        $statusRows = Db::name('content')
            ->whereNull('delete_time')
            ->group('status')
            ->column('COUNT(*)', 'status');
        $statusMap = Content::statusMap();
        $statusDistribution = [];
        foreach ($statusMap as $value => $label) {
            $statusDistribution[] = [
                'name'  => $label,
                'value' => (int) ($statusRows[$value] ?? 0),
            ];
        }

        // 分类分布
        $categoryDistribution = Db::name('content')
            ->alias('c')
            ->field('cat.name as name, COUNT(c.id) as value')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->group('c.category_id')
            ->order('value', 'desc')
            ->select()
            ->toArray();

        // 近 14 天新增趋势
        $days  = 14;
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $trendRows = Db::name('content')
            ->whereNull('delete_time')
            ->where('create_time', '>=', $start)
            ->field("DATE_FORMAT(create_time, '%Y-%m-%d') as day, COUNT(*) as total")
            ->group("DATE_FORMAT(create_time, '%Y-%m-%d')")
            ->select()
            ->toArray();
        $trendMap = array_column($trendRows, 'total', 'day');
        $trend    = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day       = date('Y-m-d', strtotime('-' . $i . ' days'));
            $trend[]   = [
                'date'  => $day,
                'label' => date('m-d', strtotime($day)),
                'total' => (int) ($trendMap[$day] ?? 0),
            ];
        }

        // 热门内容 TOP 8
        $hotContents = Db::name('content')
            ->alias('c')
            ->field('c.id, c.title, c.views, c.status, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->order('c.views', 'desc')
            ->limit(8)
            ->select()
            ->toArray();

        // 最新内容
        $latestContents = Db::name('content')
            ->alias('c')
            ->field('c.id, c.title, c.status, c.author, c.create_time, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time')
            ->order('c.id', 'desc')
            ->limit(6)
            ->select()
            ->toArray();

        // 最近操作日志
        $latestLogs = OperationLog::order('id', 'desc')->limit(8)->select()->toArray();

        $cards = [
            [
                'key'    => 'content',
                'title'  => '内容总数',
                'value'  => $total,
                'suffix' => '条',
                'desc'   => '全部内容（不含回收站）',
            ],
            [
                'key'    => 'published',
                'title'  => '已发布',
                'value'  => (int) ($statusRows[Content::STATUS_PUBLISHED] ?? 0),
                'suffix' => '条',
                'desc'   => '当前对外展示中',
            ],
            [
                'key'    => 'draft',
                'title'  => '草稿 / 下架',
                'value'  => (int) (($statusRows[Content::STATUS_DRAFT] ?? 0) + ($statusRows[Content::STATUS_OFFLINE] ?? 0)),
                'suffix' => '条',
                'desc'   => '待处理内容',
            ],
            [
                'key'    => 'views',
                'title'  => '累计浏览量',
                'value'  => (int) Db::name('content')->whereNull('delete_time')->sum('views'),
                'suffix' => '次',
                'desc'   => '全部内容浏览总量',
            ],
            [
                'key'    => 'today',
                'title'  => '今日新增',
                'value'  => (int) Content::whereNull('delete_time')->whereTime('create_time', 'today')->count(),
                'suffix' => '条',
                'desc'   => '今天创建的内容',
            ],
            [
                'key'    => 'month',
                'title'  => '本月新增',
                'value'  => (int) Content::whereNull('delete_time')->whereTime('create_time', 'month')->count(),
                'suffix' => '条',
                'desc'   => '本月创建的内容',
            ],
            [
                'key'    => 'category',
                'title'  => '分类数量',
                'value'  => (int) Category::whereNull('delete_time')->count(),
                'suffix' => '个',
                'desc'   => '内容分类总数',
            ],
            [
                'key'    => 'admin',
                'title'  => '管理员',
                'value'  => (int) Admin::whereNull('delete_time')->count(),
                'suffix' => '人',
                'desc'   => '后台账号总数',
            ],
        ];

        return $this->success([
            'cards'                => $cards,
            'statusDistribution'   => $statusDistribution,
            'categoryDistribution' => $categoryDistribution,
            'trend'                => $trend,
            'hotContents'          => $hotContents,
            'latestContents'       => $latestContents,
            'latestLogs'           => $latestLogs,
        ]);
    }
}
