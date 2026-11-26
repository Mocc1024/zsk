<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\FitnessExportService;
use app\service\FitnessService;
use think\facade\Db;

/**
 * 全民健身 - 会员（学员）管理
 *
 * 会员与 zsk_user 一对一：新增时新开前台账号；手机号可改但不回写 zsk_user。
 * 课时变动只由「教练绩效（+）」与「消课（-）」产生，本控制器不提供直接增减课时入口。
 */
class FitnessMemberController extends BaseController
{
    /** 列表（分页 + 搜索） */
    public function index()
    {
        $this->checkPermission('fitness.member.list');

        [$page, $limit] = $this->pageInfo();
        $keyword = trim((string) $this->input('keyword', ''));
        $storeId = (int) $this->input('store_id', 0);
        $coachId = (int) $this->input('coach_id', 0);
        $deleted = (int) $this->input('deleted', 0);

        $query = Db::name('fitness_member')->alias('m')
            ->field('m.*, s.name AS store_name, c.name AS coach_name, e.name AS enterprise_name')
            ->join('fitness_store s', 'm.store_id = s.id', 'LEFT')
            ->join('fitness_coach c', 'm.coach_id = c.id', 'LEFT')
            ->join('fitness_enterprise e', 'm.enterprise_id = e.id', 'LEFT');
        if ($deleted === 1) {
            $query->whereNotNull('m.delete_time');
        } else {
            $query->whereNull('m.delete_time');
        }
        if ($keyword !== '') {
            $query->where('m.name|m.mobile', 'like', '%' . $keyword . '%');
        }
        if ($storeId > 0) {
            $query->where('m.store_id', $storeId);
        }
        if ($coachId > 0) {
            $query->where('m.coach_id', $coachId);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('m.id', 'desc')->page($page, $limit)->select()->toArray();

        $this->attachMaxCardEndDate($list);

        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 详情 */
    public function read($id = 0)
    {
        $this->checkPermission('fitness.member.list');
        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_member')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('会员不存在或已禁用', 404);
        }
        return $this->success($row);
    }

    /** 新增会员（同时创建 zsk_user 账号） */
    public function save()
    {
        $this->checkPermission('fitness.member.create');

        $data   = $this->params();
        $mobile = trim((string) ($data['mobile'] ?? ''));
        $name   = trim((string) ($data['name'] ?? ''));

        $this->validate($data, [
            'name'   => 'require|max:50',
            'mobile' => 'require',
        ], [
            'name.require'   => '请输入会员姓名',
            'mobile.require' => '请输入手机号',
        ]);

        $check = FitnessService::checkMobileAvailable($mobile);
        if (!$check['ok']) {
            return $this->fail($check['msg']);
        }
        $storeId = (int) ($data['store_id'] ?? 0);
        if ($storeId <= 0 || !Db::name('fitness_store')->where('id', $storeId)->whereNull('delete_time')->find()) {
            return $this->fail('请选择有效门店');
        }

        Db::startTrans();
        try {
            $userId = FitnessService::createFrontUser($mobile, $name, trim((string) ($data['avatar'] ?? '')));
            $id = Db::name('fitness_member')->insertGetId([
                'user_id'       => $userId,
                'name'          => $name,
                'mobile'        => $mobile,
                'avatar'        => trim((string) ($data['avatar'] ?? '')),
                'birth_date'    => $data['birth_date'] ?: null,
                'bio'           => trim((string) ($data['bio'] ?? '')),
                'store_id'      => $storeId,
                'enterprise_id' => (int) ($data['enterprise_id'] ?? 0),
                'coach_id'      => 0,
                'lesson_balance'=> 0,
                'create_time'   => date('Y-m-d H:i:s'),
                'update_time'   => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('新增失败：' . $e->getMessage());
        }

        $this->log('fitness.member', 'create', '新增会员：' . $name . '（初始密码为手机号后6位）');
        return $this->success(['id' => (int) $id], '新增成功，前台登录密码为手机号后 6 位');
    }

    /** 编辑资料（手机号仅改档案，不回写 zsk_user） */
    public function update($id = 0)
    {
        $this->checkPermission('fitness.member.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $row  = Db::name('fitness_member')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('会员不存在或已禁用', 404);
        }

        if (array_key_exists('mobile', $data)) {
            $mobile = trim((string) $data['mobile']);
            if (!preg_match('/^1\d{10}$/', $mobile)) {
                return $this->fail('请输入 11 位手机号');
            }
            if ($mobile !== (string) $row['mobile']) {
                $occupied = Db::name('fitness_member')->where('mobile', $mobile)->where('id', '<>', $id)->whereNull('delete_time')->find()
                    ?: Db::name('fitness_coach')->where('mobile', $mobile)->whereNull('delete_time')->find();
                if ($occupied) {
                    return $this->fail('该手机号已关联其他档案');
                }
            }
        }

        $payload = ['update_time' => date('Y-m-d H:i:s')];
        foreach (['name', 'mobile', 'avatar', 'birth_date', 'bio', 'store_id', 'enterprise_id'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (in_array($field, ['birth_date'], true)) {
                $payload[$field] = $data[$field] ?: null;
            } elseif (in_array($field, ['store_id', 'enterprise_id'], true)) {
                $payload[$field] = (int) $data[$field];
            } else {
                $payload[$field] = trim((string) $data[$field]);
            }
        }
        Db::name('fitness_member')->where('id', $id)->update($payload);

        $this->log('fitness.member', 'update', '编辑会员 #' . $id);
        return $this->success(null, '保存成功');
    }

    /** 消课 */
    public function consume($id = 0)
    {
        $this->checkPermission('fitness.member.consume');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $member = Db::name('fitness_member')->where('id', $id)->whereNull('delete_time')->find();
        if (!$member) {
            return $this->fail('会员不存在或已禁用', 404);
        }

        $res = FitnessService::createConsume(
            $id,
            (int) $member['store_id'],
            trim((string) ($data['consume_date'] ?? '')),
            (int) ($data['lessons'] ?? 0),
            trim((string) ($data['remark'] ?? ''))
        );
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        $this->log('fitness.member', 'consume', '会员 #' . $id . ' 消课');
        return $this->success(null, $res['msg']);
    }

    /** 消课记录列表 */
    public function consumeList($id = 0)
    {
        $this->checkPermission('fitness.member.consume');

        [$page, $limit] = $this->pageInfo();
        $id = (int) ($id ?: $this->request->param('id'));
        $query = Db::name('fitness_lesson_consume')->where('member_id', $id);
        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 撤销消课记录并返还课时 */
    public function consumeDelete()
    {
        $this->checkPermission('fitness.member.consume');
        $id  = (int) $this->input('id', 0);
        $res = FitnessService::deleteConsume($id);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        $this->log('fitness.member', 'consume-delete', '撤销消课记录 #' . $id);
        return $this->success(null, $res['msg']);
    }

    /** 会员卡记录列表 */
    public function cardList($id = 0)
    {
        $this->checkPermission('fitness.member.card');

        [$page, $limit] = $this->pageInfo();
        $id = (int) ($id ?: $this->request->param('id'));
        $query = Db::name('fitness_member_card')->alias('c')
            ->field('c.*, s.name AS store_name')
            ->join('fitness_store s', 'c.store_id = s.id', 'LEFT')
            ->where('c.member_id', $id);
        $total = (int) (clone $query)->count();
        $list  = $query->order('c.id', 'desc')->page($page, $limit)->select()->toArray();
        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 会员卡管理：新增记录（金额归属学员门店） */
    public function cardCreate($id = 0)
    {
        $this->checkPermission('fitness.member.card');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $member = Db::name('fitness_member')->where('id', $id)->whereNull('delete_time')->find();
        if (!$member) {
            return $this->fail('会员不存在或已禁用', 404);
        }

        $res = FitnessService::createCard([
            'member_id'  => $id,
            'store_id'   => (int) $member['store_id'],
            'card_date'  => trim((string) ($data['card_date'] ?? '')),
            'start_date' => trim((string) ($data['start_date'] ?? '')),
            'end_date'   => trim((string) ($data['end_date'] ?? '')),
            'amount'     => (float) ($data['amount'] ?? 0),
        ]);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        $this->log('fitness.member', 'card-create', '会员 #' . $id . ' 新增会员卡记录');
        return $this->success(null, $res['msg']);
    }

    /** 冲销会员卡记录 */
    public function cardReverse()
    {
        $this->checkPermission('fitness.member.card');
        $id  = (int) $this->input('id', 0);
        $res = FitnessService::reverseRecord(FitnessService::TABLE_CARD, $id);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        $this->log('fitness.member', 'card-reverse', '冲销会员卡记录 #' . $id);
        return $this->success(null, $res['msg']);
    }

    /** 禁用会员（软删 + 禁用前台账号） */
    public function delete($id = 0)
    {
        $this->checkPermission('fitness.member.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_member')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('会员不存在或已禁用', 404);
        }

        $reasons = [];
        if ((int) $row['coach_id'] > 0) {
            $reasons[] = '已绑定教练';
        }
        if ((int) $row['lesson_balance'] > 0) {
            $reasons[] = '还有 ' . (int) $row['lesson_balance'] . ' 次课时未上完';
        }
        if ($reasons) {
            return $this->fail('该会员' . implode('，', $reasons) . '，请先解绑/消课后再禁用');
        }

        Db::startTrans();
        try {
            Db::name('fitness_member')->where('id', $id)->update(['delete_time' => date('Y-m-d H:i:s')]);
            FitnessService::disableFrontUser((int) $row['user_id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('删除失败：' . $e->getMessage());
        }

        $this->log('fitness.member', 'delete', '禁用会员 #' . $id . '：' . $row['name']);
        return $this->success(null, '禁用成功');
    }

    /** 恢复已删除（禁用）的会员 */
    public function restore($id = 0)
    {
        $this->checkPermission('fitness.member.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_member')->where('id', $id)->whereNotNull('delete_time')->find();
        if (!$row) {
            return $this->fail('会员不存在或未禁用', 404);
        }

        Db::startTrans();
        try {
            Db::name('fitness_member')->where('id', $id)->update([
                'delete_time' => null,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            FitnessService::enableFrontUser((int) $row['user_id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('恢复失败：' . $e->getMessage());
        }

        $this->log('fitness.member', 'restore', '启用会员 #' . $id . '：' . $row['name']);
        return $this->success(null, '启用成功');
    }

    /** 消课记录导出（CSV） */
    public function consumeExport($id = 0)
    {
        $this->checkPermission('fitness.member.consume');

        $id = (int) ($id ?: $this->request->param('id'));
        $rows = Db::name('fitness_lesson_consume')->alias('c')
            ->field('c.*, s.name AS store_name')
            ->join('fitness_store s', 'c.store_id = s.id', 'LEFT')
            ->where('c.member_id', $id)
            ->order('c.id', 'desc')
            ->select()->toArray();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['id'],
                $row['consume_date'] ?? '',
                $row['store_name'] ?? '',
                $row['lessons'] ?? 0,
                $row['remark'] ?? '',
                $row['create_time'] ?? '',
            ];
        }

        return $this->success(FitnessExportService::payload(
            '消课记录_' . $id . '_' . date('Ymd_His') . '.csv',
            ['记录ID', '消课日期', '门店', '课时(次)', '备注', '录入时间'],
            $data
        ));
    }

    /** 办卡记录导出（CSV） */
    public function cardExport($id = 0)
    {
        $this->checkPermission('fitness.member.card');

        $id = (int) ($id ?: $this->request->param('id'));
        $rows = Db::name('fitness_member_card')->alias('c')
            ->field('c.*, s.name AS store_name')
            ->join('fitness_store s', 'c.store_id = s.id', 'LEFT')
            ->where('c.member_id', $id)
            ->order('c.id', 'desc')
            ->select()->toArray();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['id'],
                $row['store_name'] ?? '',
                $row['card_date'] ?? '',
                $row['start_date'] ?? '',
                $row['end_date'] ?? '',
                $row['amount'] ?? 0,
                ((int) ($row['reverse_of'] ?? 0) > 0 || (float) ($row['amount'] ?? 0) < 0) ? '已冲销' : '有效',
                $row['create_time'] ?? '',
            ];
        }

        return $this->success(FitnessExportService::payload(
            '办卡记录_' . $id . '_' . date('Ymd_His') . '.csv',
            ['记录ID', '办卡门店', '办卡日期', '开始日期', '到期日期', '金额(元)', '状态', '录入时间'],
            $data
        ));
    }

    /** 会员列表导出（按当前搜索条件导出全部） */
    public function export()
    {
        $this->checkPermission('fitness.member.list');

        $keyword = trim((string) $this->input('keyword', ''));
        $storeId = (int) $this->input('store_id', 0);
        $coachId = (int) $this->input('coach_id', 0);
        $deleted = (int) $this->input('deleted', 0);

        $query = Db::name('fitness_member')->alias('m')
            ->field('m.*, s.name AS store_name, c.name AS coach_name, e.name AS enterprise_name')
            ->join('fitness_store s', 'm.store_id = s.id', 'LEFT')
            ->join('fitness_coach c', 'm.coach_id = c.id', 'LEFT')
            ->join('fitness_enterprise e', 'm.enterprise_id = e.id', 'LEFT');
        if ($deleted === 1) {
            $query->whereNotNull('m.delete_time');
        } else {
            $query->whereNull('m.delete_time');
        }
        if ($keyword !== '') {
            $query->where('m.name|m.mobile', 'like', '%' . $keyword . '%');
        }
        if ($storeId > 0) {
            $query->where('m.store_id', $storeId);
        }
        if ($coachId > 0) {
            $query->where('m.coach_id', $coachId);
        }

        $rows = $query->order('m.id', 'desc')->select()->toArray();
        $this->attachMaxCardEndDate($rows);

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['id'],
                $row['name'] ?? '',
                $row['mobile'] ?? '',
                $row['store_name'] ?? '',
                $row['coach_name'] ?? '',
                $row['enterprise_name'] ?? '',
                $row['lesson_balance'] ?? 0,
                $row['max_card_end_date'] ?? '',
                $row['delete_time'] ? '禁用' : '启用',
                $row['create_time'] ?? '',
            ];
        }

        return $this->success(FitnessExportService::payload(
            '会员列表_' . date('Ymd_His') . '.csv',
            ['ID', '姓名', '手机号', '门店', '教练', '企业', '课时余额', '最晚卡到期', '状态', '创建时间'],
            $data
        ));
    }

    /** 为会员列表补充最晚有效卡到期日（排除已冲销记录） */
    private function attachMaxCardEndDate(array &$list): void
    {
        $memberIds = array_column($list, 'id');
        $endMap    = [];
        if ($memberIds) {
            $cards = Db::name('fitness_member_card')
                ->whereIn('member_id', $memberIds)
                ->field('id,member_id,end_date,amount,reverse_of')
                ->select()->toArray();
            $reversedIds = [];
            foreach ($cards as $card) {
                if ((int) $card['reverse_of'] > 0) {
                    $reversedIds[(int) $card['reverse_of']] = true;
                }
            }
            foreach ($cards as $card) {
                $cardId = (int) $card['id'];
                if ((int) $card['reverse_of'] > 0 || (float) $card['amount'] <= 0 || isset($reversedIds[$cardId])) {
                    continue;
                }
                $mid = (int) $card['member_id'];
                $end = (string) $card['end_date'];
                if ($end !== '' && (!isset($endMap[$mid]) || strcmp($end, $endMap[$mid]) > 0)) {
                    $endMap[$mid] = $end;
                }
            }
        }
        foreach ($list as &$member) {
            $member['max_card_end_date'] = $endMap[(int) $member['id']] ?? null;
        }
        unset($member);
    }
}
