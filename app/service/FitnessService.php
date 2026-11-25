<?php
declare (strict_types = 1);

namespace app\service;

use app\model\User;
use think\facade\Db;

/**
 * 全民健身模块公共业务逻辑
 *
 * 集中处理四类跨表规则：
 *   1. 档案 ↔ zsk_user 账号的创建与禁用；
 *   2. 业绩聚合（会员卡金额 + 绩效金额，按门店快照与日期归属）；
 *   3. 课时联动（绩效增加 / 消课减少 / 冲销回滚）；
 *   4. 删除与离职前置校验。
 *
 * 所有金额与课时变动都走这里的事务方法，避免控制器各写一份导致账实不一致。
 */
class FitnessService
{
    /** 教练状态 */
    public const COACH_ACTIVE = 1;
    public const COACH_LEFT   = 2;

    /** 业绩流水表名（只读，冲销只能新增负数记录） */
    public const TABLE_CARD = 'fitness_member_card';
    public const TABLE_PERF = 'fitness_performance';

    /**
     * 校验手机号能否用于新建档案账号
     *
     * 一个 zsk_user 只能对应一个会员或一个教练：创建时统一新开账号，
     * 因此只要 zsk_user 中已存在同 username / mobile 的账号就拒绝。
     *
     * @return array{ok:bool,msg:string}
     */
    public static function checkMobileAvailable(string $mobile): array
    {
        if (!preg_match('/^1\d{10}$/', $mobile)) {
            return ['ok' => false, 'msg' => '请输入 11 位手机号'];
        }
        $exists = Db::name('user')
            ->where('username', $mobile)
            ->whereOr('mobile', $mobile)
            ->find();
        if ($exists) {
            return ['ok' => false, 'msg' => '该手机号已关联前台账号，不能重复创建档案'];
        }
        return ['ok' => true, 'msg' => ''];
    }

    /**
     * 校验某个 zsk_user 是否尚未绑定任何档案（供绑定已有账号场景预留）
     */
    public static function checkUserIdAvailable(int $userId, string $excludeTable = '', int $excludeId = 0): bool
    {
        if ($userId <= 0) {
            return false;
        }
        foreach (['fitness_member', 'fitness_coach'] as $table) {
            $query = Db::name($table)->where('user_id', $userId)->whereNull('delete_time');
            if ($table === $excludeTable && $excludeId > 0) {
                $query->where('id', '<>', $excludeId);
            }
            if ($query->find()) {
                return false;
            }
        }
        return true;
    }

    /**
     * 用手机号创建前台账号（用户名=手机号，初始密码=手机号后6位）
     *
     * User 模型的 setPasswordAttr 会自动 bcrypt 加密，这里传明文即可。
     *
     * @return int 新建的 zsk_user.id
     */
    public static function createFrontUser(string $mobile, string $name, string $avatar = ''): int
    {
        $user = new User();
        $user->username = $mobile;
        $user->password = substr($mobile, -6);
        $user->nickname = $name !== '' ? $name : $mobile;
        $user->avatar   = $avatar;
        $user->mobile   = $mobile;
        $user->status   = User::STATUS_NORMAL;
        $user->level    = 1;
        $user->save();

        return (int) $user->getAttr('id');
    }

    /**
     * 禁用前台账号（会员/企业删除、教练离职时调用）
     */
    public static function disableFrontUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        Db::name('user')->where('id', $userId)->update([
            'status'      => User::STATUS_DISABLE,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 启用前台账号（教练复职时调用）
     */
    public static function enableFrontUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        Db::name('user')->where('id', $userId)->update([
            'status'      => User::STATUS_NORMAL,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 门店统计（一次聚合，避免逐门店 N+1 查询）
     *
     * 返回以门店ID为键的指标数组；未传 storeId 时返回全部门店。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function storeStats(?int $storeId = null, ?array $storeIds = null): array
    {
        $now    = date('Y-m-d H:i:s');
        $year   = date('Y-01-01 00:00:00');
        $month  = date('Y-m-01 00:00:00');
        $today  = date('Y-m-d 00:00:00');
        $bounds = ['year' => $year, 'month' => $month, 'today' => $today, 'now' => $now];

        $stores = Db::name('fitness_store')->field('id,name,address');
        if ($storeIds !== null) {
            $stores->whereIn('id', $storeIds);
        } else {
            $stores->whereNull('delete_time')->where('status', 1);
            if ($storeId !== null && $storeId > 0) {
                $stores->where('id', $storeId);
            }
        }
        $stores = $stores->order('sort', 'desc')->order('id', 'asc')->select()->toArray();

        $ids = array_column($stores, 'id');
        if (!$ids) {
            return [];
        }

        // 业绩：会员卡（学员门店快照）+ 绩效（教练门店快照），按日期切分
        $revenue = self::revenueAgg($ids, $bounds);

        // 待上课时 / 会员数
        $memberAgg = Db::name('fitness_member')
            ->whereNull('delete_time')
            ->whereIn('store_id', $ids)
            ->field('store_id, COUNT(*) AS member_count, SUM(lesson_balance) AS balance')
            ->group('store_id')
            ->select()->toArray();
        $memberMap = [];
        foreach ($memberAgg as $row) {
            $memberMap[(int) $row['store_id']] = [
                'member_count'     => (int) $row['member_count'],
                'lesson_pending'   => (int) $row['balance'],
            ];
        }

        // 已上课时（消课累计）
        // 注意：column() 会重置 field()，若用别名 total 作为 column 字段会生成
        // SELECT total ... 导致 1054；这里用 select() 保留 SUM 别名后手工建映射。
        $consumeRows = Db::name('fitness_lesson_consume')
            ->whereIn('store_id', $ids)
            ->field('store_id, SUM(lessons) AS lessons_total')
            ->group('store_id')
            ->select()->toArray();
        $consumeMap = [];
        foreach ($consumeRows as $row) {
            $consumeMap[(int) $row['store_id']] = (int) $row['lessons_total'];
        }

        // 教练数（仅在职）
        $coachMap = Db::name('fitness_coach')
            ->whereNull('delete_time')
            ->where('status', self::COACH_ACTIVE)
            ->whereIn('store_id', $ids)
            ->group('store_id')
            ->column('COUNT(*)', 'store_id');

        // 企业数
        $enterpriseMap = Db::name('fitness_enterprise')
            ->whereNull('delete_time')
            ->whereIn('store_id', $ids)
            ->group('store_id')
            ->column('COUNT(*)', 'store_id');

        $result = [];
        foreach ($stores as $store) {
            $sid = (int) $store['id'];
            $rev = array_replace_recursive(self::emptyRevenue(), $revenue[$sid] ?? []);
            $mem = $memberMap[$sid] ?? ['member_count' => 0, 'lesson_pending' => 0];
            $result[] = [
                'store_id'        => $sid,
                'store_name'      => $store['name'],
                'address'         => $store['address'],
                'performance'     => $rev,
                'lesson_done'     => (int) ($consumeMap[$sid] ?? 0),
                'lesson_pending'  => $mem['lesson_pending'],
                'member_count'    => $mem['member_count'],
                'coach_count'     => (int) ($coachMap[$sid] ?? 0),
                'enterprise_count' => (int) ($enterpriseMap[$sid] ?? 0),
            ];
        }
        return $result;
    }

    /** 空业绩结构：合计 + 会员卡/教练绩效分项 */
    private static function emptyRevenue(): array
    {
        $zero = ['total' => 0.0, 'year' => 0.0, 'month' => 0.0, 'today' => 0.0];
        return $zero + ['card' => $zero, 'coach' => $zero];
    }

    /**
     * 业绩聚合：按门店汇总各时间范围的金额，并区分会员卡业绩与教练绩效业绩
     */
    private static function revenueAgg(array $storeIds, array $bounds): array
    {
        $in     = implode(',', array_map('intval', $storeIds));
        $prefix = (string) (config('database.connections.mysql.prefix') ?: 'zsk_');
        $rows   = [];
        $sources = [
            ['type' => 'card',  'table' => self::TABLE_CARD, 'date' => 'card_date'],
            ['type' => 'coach', 'table' => self::TABLE_PERF, 'date' => 'perf_date'],
        ];

        foreach ($sources as $source) {
            $dateField = $source['date'];
            $cases = [];
            foreach (['yr' => 'year', 'mo' => 'month', 'td' => 'today'] as $alias => $boundKey) {
                $from    = $bounds[$boundKey];
                $to      = $bounds['now'];
                $cases[] = "SUM(CASE WHEN {$dateField} >= '{$from}' AND {$dateField} <= '{$to}' THEN amount ELSE 0 END) AS {$alias}";
            }
            $sql = 'SELECT store_id, SUM(amount) AS total, ' . implode(', ', $cases)
                . ' FROM ' . $prefix . $source['table']
                . ' WHERE store_id IN (' . $in . ')'
                . ' AND ' . $dateField . ' IS NOT NULL'
                . ' GROUP BY store_id';

            foreach (Db::query($sql) as $row) {
                $sid  = (int) $row['store_id'];
                $type = $source['type'];
                if (!isset($rows[$sid])) {
                    $rows[$sid] = self::emptyRevenue();
                }
                foreach (['total' => 'total', 'year' => 'yr', 'month' => 'mo', 'today' => 'td'] as $key => $field) {
                    $value = (float) $row[$field];
                    $rows[$sid][$key] += $value;
                    $rows[$sid][$type][$key] += $value;
                }
            }
        }

        foreach ($rows as &$row) {
            foreach (['total', 'year', 'month', 'today'] as $key) {
                $row[$key] = round($row[$key], 2);
                foreach (['card', 'coach'] as $type) {
                    $row[$type][$key] = round($row[$type][$key], 2);
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * 创建绩效记录：写流水 + 给学员加课时（事务）
     *
     * @return array{ok:bool,msg:string,id:int}
     */
    public static function createPerformance(array $data): array
    {
        $coachId  = (int) $data['coach_id'];
        $memberId = (int) $data['member_id'];
        $lessons  = (int) $data['lessons'];
        $amount   = (float) $data['amount'];
        $perfDate = (string) $data['perf_date'];
        $storeId  = (int) $data['store_id'];

        $bound = Db::name('fitness_member')
            ->where('id', $memberId)
            ->where('coach_id', $coachId)
            ->whereNull('delete_time')
            ->find();
        if (!$bound) {
            return ['ok' => false, 'msg' => '该学员不是此教练名下绑定的学员', 'id' => 0];
        }
        if ($lessons <= 0) {
            return ['ok' => false, 'msg' => '课时次数必须为正整数', 'id' => 0];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '金额必须大于 0', 'id' => 0];
        }
        if ($perfDate === '') {
            return ['ok' => false, 'msg' => '请选择日期时间', 'id' => 0];
        }

        Db::startTrans();
        try {
            $id = Db::name(self::TABLE_PERF)->insertGetId([
                'coach_id'    => $coachId,
                'member_id'   => $memberId,
                'store_id'    => $storeId,
                'perf_date'   => $perfDate,
                'amount'      => $amount,
                'lessons'     => $lessons,
                'reverse_of'  => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            self::changeLessons($memberId, $lessons);
            Db::commit();
            return ['ok' => true, 'msg' => '绩效记录已保存', 'id' => (int) $id];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'msg' => '保存失败：' . $e->getMessage(), 'id' => 0];
        }
    }

    /**
     * 创建会员卡记录（流水，只写金额不动课时）
     */
    public static function createCard(array $data): array
    {
        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            return ['ok' => false, 'msg' => '金额必须大于 0'];
        }
        if (empty($data['card_date'])) {
            return ['ok' => false, 'msg' => '请选择日期时间'];
        }
        if (empty($data['start_date']) || empty($data['end_date'])) {
            return ['ok' => false, 'msg' => '请选择有效期起止日期'];
        }
        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            return ['ok' => false, 'msg' => '有效期结束日期不能早于开始日期'];
        }

        Db::name(self::TABLE_CARD)->insert([
            'member_id'   => (int) $data['member_id'],
            'store_id'    => (int) $data['store_id'],
            'card_date'   => $data['card_date'],
            'start_date'  => $data['start_date'],
            'end_date'    => $data['end_date'],
            'amount'      => $amount,
            'reverse_of'  => 0,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'msg' => '会员卡记录已保存'];
    }

    /**
     * 冲销流水记录（新增一条金额为负、课时为负的记录）
     *
     * 绩效冲销会扣回学员课时；若学员已消课导致余额不足则拒绝冲销。
     */
    public static function reverseRecord(string $table, int $recordId): array
    {
        if (!in_array($table, [self::TABLE_CARD, self::TABLE_PERF], true)) {
            return ['ok' => false, 'msg' => '不支持冲销该记录'];
        }
        $row = Db::name($table)->where('id', $recordId)->find();
        if (!$row) {
            return ['ok' => false, 'msg' => '记录不存在'];
        }
        if ((int) $row['reverse_of'] > 0 || (float) $row['amount'] < 0) {
            return ['ok' => false, 'msg' => '冲销记录不能再被冲销'];
        }
        if (Db::name($table)->where('reverse_of', $recordId)->find()) {
            return ['ok' => false, 'msg' => '该记录已冲销过，请勿重复操作'];
        }

        $lessons = 0;
        $memberId = (int) $row['member_id'];
        if ($table === self::TABLE_PERF) {
            $lessons = (int) $row['lessons'];
            $balance = (int) Db::name('fitness_member')->where('id', $memberId)->value('lesson_balance');
            if ($lessons > 0 && $balance < $lessons) {
                return ['ok' => false, 'msg' => '该学员当前课时余额不足 ' . $lessons . ' 次，无法冲销此绩效'];
            }
        }

        Db::startTrans();
        try {
            $payload = [
                'member_id'   => $memberId,
                'store_id'    => (int) $row['store_id'],
                'amount'      => -abs((float) $row['amount']),
                'lessons'     => -abs($lessons),
                'reverse_of'  => $recordId,
                'create_time' => date('Y-m-d H:i:s'),
            ];
            if ($table === self::TABLE_CARD) {
                $payload['card_date']  = $row['card_date'];
                $payload['start_date'] = $row['start_date'];
                $payload['end_date']   = $row['end_date'];
                unset($payload['lessons']);
            } else {
                $payload['coach_id']  = (int) $row['coach_id'];
                $payload['perf_date'] = $row['perf_date'];
            }
            Db::name($table)->insert($payload);
            if ($table === self::TABLE_PERF && $lessons > 0) {
                self::changeLessons($memberId, -$lessons);
            }
            Db::commit();
            return ['ok' => true, 'msg' => '已冲销'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'msg' => '冲销失败：' . $e->getMessage()];
        }
    }

    /**
     * 消课：校验余额后写记录并扣减课时（事务）
     */
    public static function createConsume(int $memberId, int $storeId, string $consumeDate, int $lessons, string $remark): array
    {
        $member = Db::name('fitness_member')->where('id', $memberId)->whereNull('delete_time')->find();
        if (!$member) {
            return ['ok' => false, 'msg' => '会员不存在或已删除'];
        }
        if ($lessons <= 0) {
            return ['ok' => false, 'msg' => '消课次数必须为正整数'];
        }
        if ($consumeDate === '') {
            return ['ok' => false, 'msg' => '请选择上课日期时间'];
        }
        if ((int) $member['lesson_balance'] < $lessons) {
            return ['ok' => false, 'msg' => '课时余额不足，当前剩余 ' . $member['lesson_balance'] . ' 次'];
        }

        Db::startTrans();
        try {
            Db::name('fitness_lesson_consume')->insert([
                'member_id'    => $memberId,
                'store_id'     => $storeId,
                'consume_date' => $consumeDate,
                'lessons'      => $lessons,
                'remark'       => $remark,
                'create_time'  => date('Y-m-d H:i:s'),
            ]);
            self::changeLessons($memberId, -$lessons);
            Db::commit();
            return ['ok' => true, 'msg' => '消课成功'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'msg' => '消课失败：' . $e->getMessage()];
        }
    }

    /**
     * 删除消课记录并回滚课时（事务）
     */
    public static function deleteConsume(int $recordId): array
    {
        $row = Db::name('fitness_lesson_consume')->where('id', $recordId)->find();
        if (!$row) {
            return ['ok' => false, 'msg' => '消课记录不存在'];
        }
        Db::startTrans();
        try {
            Db::name('fitness_lesson_consume')->where('id', $recordId)->delete();
            self::changeLessons((int) $row['member_id'], (int) $row['lessons']);
            Db::commit();
            return ['ok' => true, 'msg' => '已撤销并返还课时'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'msg' => '撤销失败：' . $e->getMessage()];
        }
    }

    /**
     * 课时余额增减（统一入口）
     */
    private static function changeLessons(int $memberId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        Db::name('fitness_member')->where('id', $memberId)
            ->inc('lesson_balance', $delta)
            ->update();
    }

    /**
     * 教练离职前置校验：名下只要有学员就不允许离职，需先解绑
     *
     * @return array{ok:bool,msg:string,students:int}
     */
    public static function checkResign(int $coachId): array
    {
        $students = (int) Db::name('fitness_member')
            ->where('coach_id', $coachId)
            ->whereNull('delete_time')
            ->count();
        if ($students > 0) {
            return [
                'ok' => false,
                'msg' => '该教练名下还有 ' . $students . ' 名学员，请先解绑后再办理离职',
                'students' => $students,
            ];
        }
        return ['ok' => true, 'msg' => '', 'students' => 0];
    }

    /**
     * 门店占用检查（删除前调用）
     *
     * @return array<string,int>
     */
    public static function storeUsage(int $storeId): array
    {
        return [
            'member'    => (int) Db::name('fitness_member')->where('store_id', $storeId)->whereNull('delete_time')->count(),
            'coach'     => (int) Db::name('fitness_coach')->where('store_id', $storeId)->whereNull('delete_time')->count(),
            'enterprise' => (int) Db::name('fitness_enterprise')->where('store_id', $storeId)->whereNull('delete_time')->count(),
        ];
    }
}
