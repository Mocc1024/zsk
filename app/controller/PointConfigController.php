<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\Setting;
use think\facade\Db;

/**
 * 后台：积分规则配置与积分统计
 *
 * 规则存在 zsk_setting（group=points），运营改完即时生效（Setting::set 会清缓存）。
 */
class PointConfigController extends BaseController
{
    /** 可配置项：键 => [显示名, 说明] */
    private const FIELDS = [
        'signin_base'          => ['签到基础积分', '每日首次签到赠送'],
        'signin_bonus'         => ['连续签到奖励', '连续签到满 7 天额外赠送'],
        'signin_exp'           => ['签到获得经验', '每日首次签到赠送'],
        'comment_gain'         => ['评论获得积分', '每发表一条通过审核的评论'],
        'comment_daily_limit'  => ['评论每日积分上限', '防止刷评论刷积分'],
        'exp_per_comment'      => ['评论获得经验', '每发表一条通过审核的评论'],
    ];

    /**
     * 规则列表
     */
    public function rules()
    {
        $this->checkPermission('points.rule');

        $list = [];
        foreach (self::FIELDS as $key => [$name, $remark]) {
            $list[] = [
                'key'    => $key,
                'name'   => $name,
                'remark' => $remark,
                'value'  => Setting::get('points.' . $key, 0),
            ];
        }

        return $this->success(['list' => $list]);
    }

    /**
     * 保存规则
     */
    public function saveRules()
    {
        $this->checkPermission('points.rule');

        $data = $this->params();
        $saved = 0;

        foreach (self::FIELDS as $key => $meta) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = (int) $data[$key];
            if ($value < 0) {
                return $this->fail($meta[0] . '不能为负数');
            }
            Setting::set('points', $key, (string) $value);
            $saved++;
        }

        $this->log('points', 'rule', '更新积分规则，' . $saved . ' 项');

        return $this->success(null, '规则已保存');
    }

    /**
     * 积分统计
     */
    public function stat()
    {
        $this->checkPermission('points.stat');

        $gain  = (int) Db::name('user_points_log')->where('type', 1)->sum('points');
        $cost  = (int) Db::name('user_points_log')->where('type', 2)->sum('points');

        // 分场景发放明细
        $byScene = Db::name('user_points_log')
            ->field('scene, type, COUNT(*) as cnt, SUM(points) as total')
            ->group('scene, type')
            ->select()
            ->toArray();

        $top = Db::name('user')
            ->whereNull('delete_time')
            ->field('id, username, nickname, points, level')
            ->order('points', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        return $this->success([
            'gain'     => $gain,
            'cost'     => $cost,
            'byScene'  => $byScene,
            'topUsers' => $top,
        ]);
    }
}
