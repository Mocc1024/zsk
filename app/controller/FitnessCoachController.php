<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\FitnessExportService;
use app\service\FitnessService;
use think\facade\Db;

/**
 * 全民健身 - 教练管理
 *
 * 教练档案与 zsk_user 一对一：加入教练时新开前台账号（手机号=用户名，
 * 初始密码=手机号后6位）；档案手机号可改，但不回写 zsk_user。
 */
class FitnessCoachController extends BaseController
{
    /** 列表（分页 + 搜索） */
    public function index()
    {
        $this->checkPermission('fitness.coach.list');

        [$page, $limit] = $this->pageInfo();
        $keyword = trim((string) $this->input('keyword', ''));
        $status  = (int) $this->input('status', 1);
        $storeId = (int) $this->input('store_id', 0);

        $query = Db::name('fitness_coach')->alias('c')
            ->field('c.*, s.name AS store_name')
            ->join('fitness_store s', 'c.store_id = s.id', 'LEFT')
            ->whereNull('c.delete_time');
        if ($keyword !== '') {
            $query->where('c.name|c.mobile', 'like', '%' . $keyword . '%');
        }
        if ($storeId > 0) {
            $query->where('c.store_id', $storeId);
        }
        if (in_array($status, [1, 2], true)) {
            $query->where('c.status', $status);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('c.id', 'desc')->page($page, $limit)->select()->toArray();

        $coachIds = array_column($list, 'id');
        $studentCounts = [];
        if ($coachIds) {
            $studentCounts = Db::name('fitness_member')
                ->whereIn('coach_id', $coachIds)->whereNull('delete_time')
                ->group('coach_id')->column('COUNT(*)', 'coach_id');
        }
        foreach ($list as &$item) {
            $item['student_count']   = (int) ($studentCounts[$item['id']] ?? 0);
            $item['certificates']    = json_decode((string) $item['certificates'], true) ?: [];
            $item['status_text']     = (int) $item['status'] === FitnessService::COACH_ACTIVE ? '在职' : '离职';
        }
        unset($item);

        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 在职教练下拉选项 */
    public function options()
    {
        $this->checkPermission('fitness.coach.list');
        $list = Db::name('fitness_coach')
            ->whereNull('delete_time')->where('status', FitnessService::COACH_ACTIVE)
            ->field('id,name,store_id')->order('id', 'asc')->select()->toArray();
        return $this->success($list);
    }

    /** 详情 */
    public function read($id = 0)
    {
        $this->checkPermission('fitness.coach.list');
        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('教练不存在或已删除', 404);
        }
        $row['certificates'] = json_decode((string) $row['certificates'], true) ?: [];
        return $this->success($row);
    }

    /** 加入教练（同时创建 zsk_user 账号） */
    public function save()
    {
        $this->checkPermission('fitness.coach.create');

        $data   = $this->params();
        $mobile = trim((string) ($data['mobile'] ?? ''));
        $name   = trim((string) ($data['name'] ?? ''));

        $this->validate($data, [
            'name'   => 'require|max:50',
            'mobile' => 'require',
        ], [
            'name.require'   => '请输入教练姓名',
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
            $id = Db::name('fitness_coach')->insertGetId([
                'user_id'      => $userId,
                'name'         => $name,
                'mobile'       => $mobile,
                'avatar'       => trim((string) ($data['avatar'] ?? '')),
                'birth_date'   => $data['birth_date'] ?: null,
                'bio'          => trim((string) ($data['bio'] ?? '')),
                'certificates' => json_encode($this->cleanUrls($data['certificates'] ?? []), JSON_UNESCAPED_UNICODE),
                'store_id'     => $storeId,
                'status'       => FitnessService::COACH_ACTIVE,
                'create_time'  => date('Y-m-d H:i:s'),
                'update_time'  => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('加入失败：' . $e->getMessage());
        }

        $this->log('fitness.coach', 'create', '加入教练：' . $name . '（初始密码为手机号后6位）');
        return $this->success(['id' => (int) $id], '已加入，前台登录密码为手机号后 6 位');
    }

    /** 编辑资料（手机号仅改档案，不回写 zsk_user） */
    public function update($id = 0)
    {
        $this->checkPermission('fitness.coach.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $row  = Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('教练不存在或已删除', 404);
        }

        if (array_key_exists('mobile', $data)) {
            $mobile = trim((string) $data['mobile']);
            if (!preg_match('/^1\d{10}$/', $mobile)) {
                return $this->fail('请输入 11 位手机号');
            }
            if ($mobile !== (string) $row['mobile']) {
                $occupied = Db::name('fitness_coach')->where('mobile', $mobile)->where('id', '<>', $id)->whereNull('delete_time')->find()
                    ?: Db::name('fitness_member')->where('mobile', $mobile)->whereNull('delete_time')->find();
                if ($occupied) {
                    return $this->fail('该手机号已关联其他档案');
                }
            }
        }

        $payload = ['update_time' => date('Y-m-d H:i:s')];
        foreach (['name', 'mobile', 'avatar', 'birth_date', 'bio'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = in_array($field, ['birth_date'], true)
                    ? ($data[$field] ?: null)
                    : trim((string) $data[$field]);
            }
        }
        if (array_key_exists('certificates', $data)) {
            $payload['certificates'] = json_encode($this->cleanUrls($data['certificates']), JSON_UNESCAPED_UNICODE);
        }
        Db::name('fitness_coach')->where('id', $id)->update($payload);

        $this->log('fitness.coach', 'update', '编辑教练 #' . $id);
        return $this->success(null, '保存成功');
    }

    /** 绑定门店 */
    public function bindStore($id = 0)
    {
        $this->checkPermission('fitness.coach.bind-store');

        $id      = (int) ($id ?: $this->request->param('id'));
        $storeId = (int) $this->input('store_id', 0);
        if (!Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find()) {
            return $this->fail('教练不存在或已删除', 404);
        }
        if ($storeId <= 0 || !Db::name('fitness_store')->where('id', $storeId)->whereNull('delete_time')->find()) {
            return $this->fail('请选择有效门店');
        }
        Db::name('fitness_coach')->where('id', $id)->update([
            'store_id' => $storeId, 'update_time' => date('Y-m-d H:i:s'),
        ]);
        $this->log('fitness.coach', 'bind-store', '教练 #' . $id . ' 绑定门店 #' . $storeId);
        return $this->success(null, '绑定成功');
    }

    /** 绑定学员（提交的学员改绑到本教练，一个学员只能有一个教练） */
    public function bindStudents($id = 0)
    {
        $this->checkPermission('fitness.coach.bind-students');

        $id        = (int) ($id ?: $this->request->param('id'));
        $memberIds = $this->input('member_ids', []);
        if (!is_array($memberIds)) {
            return $this->fail('学员列表格式不正确');
        }
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if (!Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find()) {
            return $this->fail('教练不存在或已删除', 404);
        }

        // 已被其他教练绑定的学员不允许被这里再次绑定
        if ($memberIds) {
            $conflicts = Db::name('fitness_member')
                ->whereIn('id', $memberIds)
                ->whereNull('delete_time')
                ->where('coach_id', '>', 0)
                ->where('coach_id', '<>', $id)
                ->column('name');
            if ($conflicts) {
                return $this->fail('以下学员已绑定其他教练，无法绑定：' . implode('、', $conflicts));
            }
        }

        Db::startTrans();
        try {
            // 先解除本教练现有绑定，再绑定本次提交的学员（支持取消勾选）
            Db::name('fitness_member')->where('coach_id', $id)->whereNull('delete_time')
                ->update(['coach_id' => 0, 'update_time' => date('Y-m-d H:i:s')]);
            if ($memberIds) {
                Db::name('fitness_member')->whereIn('id', $memberIds)->whereNull('delete_time')
                    ->update(['coach_id' => $id, 'update_time' => date('Y-m-d H:i:s')]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('绑定失败：' . $e->getMessage());
        }

        $this->log('fitness.coach', 'bind-students', '教练 #' . $id . ' 绑定 ' . count($memberIds) . ' 名学员');
        return $this->success(null, '绑定成功');
    }

    /** 可选学员列表（全部门店会员，标注当前所属教练） */
    public function studentOptions()
    {
        $this->checkPermission('fitness.coach.bind-students');
        $keyword = trim((string) $this->input('keyword', ''));
        $query = Db::name('fitness_member')->alias('m')
            ->leftJoin('fitness_coach c', 'm.coach_id = c.id')
            ->whereNull('m.delete_time')
            ->field('m.id,m.name,m.mobile,m.coach_id,m.store_id,c.name AS coach_name');
        if ($keyword !== '') {
            $query->where('m.name|m.mobile', 'like', '%' . $keyword . '%');
        }
        return $this->success($query->order('m.id', 'desc')->limit(200)->select()->toArray());
    }

    /** 本教练名下已绑定的学员（绩效弹框专用） */
    public function boundStudents($id = 0)
    {
        $this->checkPermission('fitness.coach.performance');
        $id = (int) ($id ?: $this->request->param('id'));
        $list = Db::name('fitness_member')
            ->where('coach_id', $id)->whereNull('delete_time')
            ->field('id,name,mobile,store_id')->order('id', 'desc')->select()->toArray();
        return $this->success($list);
    }

    /** 绩效管理：新增一条绩效（单选学员） */
    public function performance($id = 0)
    {
        $this->checkPermission('fitness.coach.performance');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $coach = Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find();
        if (!$coach) {
            return $this->fail('教练不存在或已删除', 404);
        }
        if ((int) $coach['status'] !== FitnessService::COACH_ACTIVE) {
            return $this->fail('该教练已离职，不能再记录绩效');
        }

        $res = FitnessService::createPerformance([
            'coach_id'  => $id,
            'member_id' => (int) ($data['member_id'] ?? 0),
            'store_id'  => (int) $coach['store_id'],
            'perf_date' => trim((string) ($data['perf_date'] ?? '')),
            'amount'    => (float) ($data['amount'] ?? 0),
            'lessons'   => (int) ($data['lessons'] ?? 0),
        ]);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        $this->log('fitness.coach', 'performance', '教练 #' . $id . ' 新增绩效 #' . $res['id']);
        return $this->success(['id' => $res['id']], '保存成功');
    }

    /** 绩效记录列表（含冲销） */
    public function performanceList($id = 0)
    {
        $this->checkPermission('fitness.coach.performance');

        [$page, $limit] = $this->pageInfo();
        $id = (int) ($id ?: $this->request->param('id'));

        $query = Db::name('fitness_performance')->alias('p')
            ->field('p.*, m.name AS member_name, s.name AS store_name')
            ->join('fitness_member m', 'p.member_id = m.id', 'LEFT')
            ->join('fitness_store s', 'p.store_id = s.id', 'LEFT')
            ->where('p.coach_id', $id);
        $total = (int) (clone $query)->count();
        $list  = $query->order('p.id', 'desc')->page($page, $limit)->select()->toArray();
        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 冲销绩效记录 */
    public function performanceReverse()
    {
        $this->checkPermission('fitness.coach.performance');
        $id = (int) $this->input('id', 0);
        $res = FitnessService::reverseRecord(FitnessService::TABLE_PERF, $id);
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        $this->log('fitness.coach', 'performance-reverse', '冲销绩效 #' . $id);
        return $this->success(null, $res['msg']);
    }

    /** 离职（名下存在学员时拒绝，需先解绑） */
    public function resign($id = 0)
    {
        $this->checkPermission('fitness.coach.resign');

        $id = (int) ($id ?: $this->request->param('id'));
        $coach = Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find();
        if (!$coach) {
            return $this->fail('教练不存在或已删除', 404);
        }
        if ((int) $coach['status'] === FitnessService::COACH_LEFT) {
            return $this->fail('该教练已办理离职');
        }

        $check = FitnessService::checkResign($id);
        if (!$check['ok']) {
            return $this->fail($check['msg']);
        }

        Db::startTrans();
        try {
            Db::name('fitness_coach')->where('id', $id)->update([
                'status'      => FitnessService::COACH_LEFT,
                'leave_time'  => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            Db::name('fitness_member')->where('coach_id', $id)->whereNull('delete_time')
                ->update(['coach_id' => 0, 'update_time' => date('Y-m-d H:i:s')]);
            FitnessService::disableFrontUser((int) $coach['user_id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('离职办理失败：' . $e->getMessage());
        }

        $this->log('fitness.coach', 'resign', '教练离职 #' . $id . '：' . $coach['name']);
        return $this->success(null, '离职办理成功');
    }

    /** 复职（撤销离职，恢复在职状态与前台账号） */
    public function restore($id = 0)
    {
        $this->checkPermission('fitness.coach.resign');

        $id = (int) ($id ?: $this->request->param('id'));
        $coach = Db::name('fitness_coach')->where('id', $id)->whereNull('delete_time')->find();
        if (!$coach) {
            return $this->fail('教练不存在或已删除', 404);
        }
        if ((int) $coach['status'] !== FitnessService::COACH_LEFT) {
            return $this->fail('该教练未离职，无需恢复');
        }

        Db::startTrans();
        try {
            Db::name('fitness_coach')->where('id', $id)->update([
                'status'      => FitnessService::COACH_ACTIVE,
                'leave_time'  => null,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            FitnessService::enableFrontUser((int) $coach['user_id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('恢复失败：' . $e->getMessage());
        }

        $this->log('fitness.coach', 'restore', '教练复职 #' . $id . '：' . $coach['name']);
        return $this->success(null, '恢复成功');
    }

    /** 绩效记录导出（CSV，Excel 可直接打开） */
    public function performanceExport($id = 0)
    {
        $this->checkPermission('fitness.coach.performance');

        $id = (int) ($id ?: $this->request->param('id'));
        $rows = Db::name('fitness_performance')->alias('p')
            ->field('p.*, m.name AS member_name, s.name AS store_name')
            ->join('fitness_member m', 'p.member_id = m.id', 'LEFT')
            ->join('fitness_store s', 'p.store_id = s.id', 'LEFT')
            ->where('p.coach_id', $id)
            ->order('p.id', 'desc')
            ->select()->toArray();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['id'],
                $row['member_name'] ?? '',
                $row['store_name'] ?? '',
                $row['perf_date'] ?? '',
                $row['amount'] ?? 0,
                $row['lessons'] ?? 0,
                ((int) ($row['reverse_of'] ?? 0) > 0 || (float) ($row['amount'] ?? 0) < 0) ? '已冲销' : '有效',
                $row['create_time'] ?? '',
            ];
        }

        return $this->success(FitnessExportService::payload(
            '绩效记录_' . $id . '_' . date('Ymd_His') . '.csv',
            ['记录ID', '会员', '门店', '绩效日期', '金额(元)', '课时(次)', '状态', '录入时间'],
            $data
        ));
    }

    /** 清洗证书照片 URL 列表 */
    private function cleanUrls($urls): array
    {
        if (!is_array($urls)) {
            return [];
        }
        return array_values(array_filter(array_map(function ($u) {
            $u = trim((string) $u);
            return $u !== '' ? $u : null;
        }, $urls)));
    }
}
