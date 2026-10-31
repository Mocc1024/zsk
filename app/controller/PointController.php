<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\Context;
use app\service\PointService;
use think\facade\Db;

/**
 * 签到 / 积分 / 等级（M3.6）
 */
class PointController extends BaseController
{
    /**
     * 每日签到
     */
    public function signin()
    {
        $result = PointService::signin(Context::userId());

        return $this->success([
            'signed'  => $result['ok'],
            'days'    => $result['days'],
            'points'  => $result['points'],
            'bonus'   => $result['bonus'],
        ], $result['msg']);
    }

    /**
     * 签到状态：今日是否已签到、连续天数
     */
    public function signinStatus()
    {
        $userId = Context::userId();
        $today  = date('Y-m-d');

        $row = Db::name('user_signin')
            ->where('user_id', $userId)
            ->where('date', $today)
            ->find();

        return $this->success([
            'signed' => !empty($row),
            'days'   => $row ? (int) (is_array($row) ? $row['days'] : $row->days) : 0,
        ]);
    }

    /**
     * 积分流水
     */
    public function points()
    {
        [$page, $limit] = $this->pageInfo();

        $query = Db::name('user_points_log')->where('user_id', Context::userId());

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
            'balance'  => (int) Db::name('user')->where('id', Context::userId())->value('points'),
        ]);
    }

    /**
     * 经验流水
     */
    public function exp()
    {
        [$page, $limit] = $this->pageInfo();

        $query = Db::name('user_exp_log')->where('user_id', Context::userId());

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 等级标识（免鉴权，前台展示成长体系）
     *
     * 只用于展示：等级是标记，不代表任何功能权限，rights_desc 也只写标识说明。
     */
    public function levels()
    {
        $list = Db::name('user_level')
            ->where('status', 1)
            ->field('level, name, icon, min_exp, rights_desc')
            ->order('level', 'asc')
            ->select()
            ->toArray();

        return $this->success(['list' => $list]);
    }
}
