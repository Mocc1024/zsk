<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 积分与经验记账
 *
 * 两条铁律：
 *   1. 余额与流水必须同一个事务写入 —— 只改余额不记流水，用户一申诉就没凭据；
 *      只记流水不改余额，账就是错的。
 *   2. 积分规则从 zsk_setting 读（后台可配），不写死在代码里。
 */
class PointService
{
    /**
     * 变动积分（正数为获得，负数为消耗）
     *
     * @return bool 积分不足时返回 false
     */
    public static function change(int $userId, int $points, string $scene, string $remark = ''): bool
    {
        if ($userId <= 0 || $points === 0) {
            return false;
        }

        return Db::transaction(function () use ($userId, $points, $scene, $remark) {
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$user) {
                return false;
            }
            $user = is_array($user) ? $user : $user->toArray();

            $balance = (int) $user['points'] + $points;
            if ($balance < 0) {
                return false;
            }

            Db::name('user')->where('id', $userId)->update(['points' => $balance]);

            Db::name('user_points_log')->insert([
                'user_id'     => $userId,
                'type'        => $points > 0 ? 1 : 2,
                'points'      => abs($points),
                'balance'     => $balance,
                'scene'       => $scene,
                'remark'      => $remark,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /**
     * 增加经验并触发升级
     */
    public static function addExp(int $userId, int $exp, string $scene = ''): int
    {
        if ($userId <= 0 || $exp <= 0) {
            return 0;
        }

        return Db::transaction(function () use ($userId, $exp, $scene) {
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$user) {
                return 0;
            }
            $user = is_array($user) ? $user : $user->toArray();

            $balance = (int) $user['exp'] + $exp;

            Db::name('user_exp_log')->insert([
                'user_id'     => $userId,
                'exp'         => $exp,
                'balance'     => $balance,
                'scene'       => $scene,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            $level = self::levelFor($balance);
            Db::name('user')->where('id', $userId)->update([
                'exp'   => $balance,
                'level' => $level,
            ]);

            return $level;
        });
    }

    /**
     * 每日签到
     *
     * 连续天数：看昨天有没有签到记录，有就 +1，没有就重新从 1 开始。
     * 连续满 7 天额外奖励（signin_bonus）。
     *
     * @return array ['ok' => bool, 'msg' => string, 'days' => int, 'points' => int, 'bonus' => int]
     */
    public static function signin(int $userId): array
    {
        $today = date('Y-m-d');

        $todayRow = Db::name('user_signin')
            ->where('user_id', $userId)
            ->where('date', $today)
            ->find();

        if ($todayRow) {
            $todayRow = is_array($todayRow) ? $todayRow : $todayRow->toArray();
            return [
                'ok'     => false,
                'msg'    => '今日已签到',
                'days'   => (int) $todayRow['days'],
                'points' => 0,
                'bonus'  => 0,
            ];
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $prev      = Db::name('user_signin')
            ->where('user_id', $userId)
            ->where('date', $yesterday)
            ->find();

        $days = $prev ? (int) (is_array($prev) ? $prev['days'] : $prev->days) + 1 : 1;

        $base  = Setting::int('points.signin_base', 5);
        $bonus = ($days % 7 === 0) ? Setting::int('points.signin_bonus', 10) : 0;
        $exp   = Setting::int('points.signin_exp', 5);

        Db::name('user_signin')->insert([
            'user_id'     => $userId,
            'date'        => $today,
            'days'        => $days,
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        self::change($userId, $base + $bonus, 'signin', '签到第 ' . $days . ' 天');
        self::addExp($userId, $exp, 'signin');

        return [
            'ok'     => true,
            'msg'    => $bonus > 0 ? '签到成功，连续 ' . $days . ' 天，额外奖励 ' . $bonus . ' 积分' : '签到成功',
            'days'   => $days,
            'points' => $base + $bonus,
            'bonus'  => $bonus,
        ];
    }

    /**
     * 发表评论的积分与经验（受每日上限约束）
     */
    public static function gainForComment(int $userId, int $commentId): void
    {
        $points = Setting::int('points.comment_gain', 2);
        $exp    = Setting::int('points.exp_per_comment', 5);
        $limit  = Setting::int('points.comment_daily_limit', 10);

        if ($points > 0 && self::withinDailyLimit($userId, 'comment', $limit)) {
            self::change($userId, $points, 'comment', '发表评论 #' . $commentId);
        }
        if ($exp > 0) {
            self::addExp($userId, $exp, 'comment');
        }
    }

    /**
     * 当日某场景是否已达到积分上限
     */
    public static function withinDailyLimit(int $userId, string $scene, int $limit): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $today = date('Y-m-d');
        $given = (int) Db::name('user_points_log')
            ->where('user_id', $userId)
            ->where('scene', $scene)
            ->where('create_time', '>=', $today . ' 00:00:00')
            ->where('create_time', '<=', $today . ' 23:59:59')
            ->sum('points');

        return $given < $limit;
    }

    /**
     * 按经验算等级：取经验门槛不超过当前经验的最高等级
     */
    public static function levelFor(int $exp): int
    {
        $levels = Db::name('user_level')
            ->where('status', 1)
            ->field('level, min_exp')
            ->order('level', 'desc')
            ->select()
            ->toArray();

        foreach ($levels as $row) {
            if ($exp >= (int) $row['min_exp']) {
                return (int) $row['level'];
            }
        }

        return 1;
    }
}
