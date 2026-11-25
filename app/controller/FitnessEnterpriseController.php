<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\FitnessExportService;
use think\facade\Db;

/**
 * 全民健身 - 企业管理
 *
 * 企业必须绑定门店；名下有学员时不可删除。
 */
class FitnessEnterpriseController extends BaseController
{
    /** 列表（分页 + 搜索） */
    public function index()
    {
        $this->checkPermission('fitness.enterprise.list');

        [$page, $limit] = $this->pageInfo();
        $keyword = trim((string) $this->input('keyword', ''));
        $deleted = (int) $this->input('deleted', 0);
        $storeId = (int) $this->input('store_id', 0);

        $query = Db::name('fitness_enterprise')->alias('e')
            ->field('e.*, s.name AS store_name')
            ->join('fitness_store s', 'e.store_id = s.id', 'LEFT');
        if ($deleted === 1) {
            $query->whereNotNull('e.delete_time');
        } else {
            $query->whereNull('e.delete_time');
        }
        if ($keyword !== '') {
            $query->where('e.name|e.guild_name', 'like', '%' . $keyword . '%');
        }
        if ($storeId > 0) {
            $query->where('e.store_id', $storeId);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('e.id', 'desc')->page($page, $limit)->select()->toArray();

        $ids = array_column($list, 'id');
        $studentCounts = [];
        if ($ids) {
            $studentCounts = Db::name('fitness_member')
                ->whereIn('enterprise_id', $ids)->whereNull('delete_time')
                ->group('enterprise_id')->column('COUNT(*)', 'enterprise_id');
        }
        foreach ($list as &$item) {
            $item['student_count'] = (int) ($studentCounts[$item['id']] ?? 0);
        }
        unset($item);

        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 下拉选项 */
    public function options()
    {
        $this->checkPermission('fitness.enterprise.list');
        $list = Db::name('fitness_enterprise')->whereNull('delete_time')
            ->field('id,name,store_id')->order('id', 'asc')->select()->toArray();
        return $this->success($list);
    }

    /** 详情 */
    public function read($id = 0)
    {
        $this->checkPermission('fitness.enterprise.list');
        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_enterprise')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('企业不存在或已删除', 404);
        }
        return $this->success($row);
    }

    /** 新增（门店必填） */
    public function save()
    {
        $this->checkPermission('fitness.enterprise.create');

        $data = $this->params();
        $this->validate($data, [
            'name'    => 'require|max:100',
            'store_id' => 'require',
        ], [
            'name.require'    => '请输入企业名称',
            'store_id.require'=> '请选择绑定门店',
        ]);

        $storeId = (int) ($data['store_id'] ?? 0);
        if ($storeId <= 0 || !Db::name('fitness_store')->where('id', $storeId)->whereNull('delete_time')->find()) {
            return $this->fail('请选择有效门店');
        }

        $id = Db::name('fitness_enterprise')->insertGetId([
            'name'        => trim((string) $data['name']),
            'intro'       => trim((string) ($data['intro'] ?? '')),
            'guild_name'  => trim((string) ($data['guild_name'] ?? '')),
            'guild_intro' => trim((string) ($data['guild_intro'] ?? '')),
            'store_id'    => $storeId,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('fitness.enterprise', 'create', '新增企业：' . $data['name']);
        return $this->success(['id' => (int) $id], '新增成功');
    }

    /** 编辑 */
    public function update($id = 0)
    {
        $this->checkPermission('fitness.enterprise.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $row  = Db::name('fitness_enterprise')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('企业不存在或已删除', 404);
        }
        $this->validate($data, [
            'name' => 'require|max:100',
        ], ['name.require' => '请输入企业名称']);

        $payload = ['update_time' => date('Y-m-d H:i:s')];
        foreach (['name', 'intro', 'guild_name', 'guild_intro'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = trim((string) $data[$field]);
            }
        }
        if (array_key_exists('store_id', $data)) {
            $storeId = (int) $data['store_id'];
            if ($storeId <= 0 || !Db::name('fitness_store')->where('id', $storeId)->whereNull('delete_time')->find()) {
                return $this->fail('请选择有效门店');
            }
            $payload['store_id'] = $storeId;
        }
        Db::name('fitness_enterprise')->where('id', $id)->update($payload);

        $this->log('fitness.enterprise', 'update', '编辑企业 #' . $id);
        return $this->success(null, '保存成功');
    }

    /** 绑定学员（提交的学员改绑到本企业，一个学员只能有一个企业） */
    public function bindStudents($id = 0)
    {
        $this->checkPermission('fitness.enterprise.bind-students');

        $id        = (int) ($id ?: $this->request->param('id'));
        $memberIds = $this->input('member_ids', []);
        if (!is_array($memberIds)) {
            return $this->fail('学员列表格式不正确');
        }
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if (!Db::name('fitness_enterprise')->where('id', $id)->whereNull('delete_time')->find()) {
            return $this->fail('企业不存在或已删除', 404);
        }

        // 已被其他企业绑定的学员不允许被这里再次绑定
        if ($memberIds) {
            $conflicts = Db::name('fitness_member')
                ->whereIn('id', $memberIds)
                ->whereNull('delete_time')
                ->where('enterprise_id', '>', 0)
                ->where('enterprise_id', '<>', $id)
                ->column('name');
            if ($conflicts) {
                return $this->fail('以下学员已绑定其他企业，无法绑定：' . implode('、', $conflicts));
            }
        }

        Db::startTrans();
        try {
            Db::name('fitness_member')->where('enterprise_id', $id)->whereNull('delete_time')
                ->update(['enterprise_id' => 0, 'update_time' => date('Y-m-d H:i:s')]);
            if ($memberIds) {
                Db::name('fitness_member')->whereIn('id', $memberIds)->whereNull('delete_time')
                    ->update(['enterprise_id' => $id, 'update_time' => date('Y-m-d H:i:s')]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('绑定失败：' . $e->getMessage());
        }

        $this->log('fitness.enterprise', 'bind-students', '企业 #' . $id . ' 绑定 ' . count($memberIds) . ' 名学员');
        return $this->success(null, '绑定成功');
    }

    /** 企业已绑定学员ID列表（回显用） */
    public function boundStudents($id = 0)
    {
        $this->checkPermission('fitness.enterprise.bind-students');
        $id = (int) ($id ?: $this->request->param('id'));
        $ids = Db::name('fitness_member')->where('enterprise_id', $id)->whereNull('delete_time')
            ->column('id');
        return $this->success(array_map('intval', $ids));
    }

    /** 删除（有绑定学员时拒绝 + 禁用账号不涉及，企业无 user_id） */
    public function delete($id = 0)
    {
        $this->checkPermission('fitness.enterprise.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_enterprise')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('企业不存在或已删除', 404);
        }

        $used = (int) Db::name('fitness_member')->where('enterprise_id', $id)->whereNull('delete_time')->count();
        if ($used > 0) {
            return $this->fail('该企业下还有 ' . $used . ' 名绑定学员，请先移走后再删除');
        }

        Db::name('fitness_enterprise')->where('id', $id)->update(['delete_time' => date('Y-m-d H:i:s')]);

        $this->log('fitness.enterprise', 'delete', '删除企业 #' . $id . '：' . $row['name']);
        return $this->success(null, '删除成功');
    }

    /** 恢复已删除（禁用）的企业 */
    public function restore($id = 0)
    {
        $this->checkPermission('fitness.enterprise.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_enterprise')->where('id', $id)->whereNotNull('delete_time')->find();
        if (!$row) {
            return $this->fail('企业不存在或未禁用', 404);
        }

        Db::name('fitness_enterprise')->where('id', $id)->update([
            'delete_time' => null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('fitness.enterprise', 'restore', '恢复企业 #' . $id . '：' . $row['name']);
        return $this->success(null, '恢复成功');
    }

    /** 企业列表导出（按当前搜索条件导出全部） */
    public function export()
    {
        $this->checkPermission('fitness.enterprise.list');

        $keyword = trim((string) $this->input('keyword', ''));
        $deleted = (int) $this->input('deleted', 0);
        $storeId = (int) $this->input('store_id', 0);

        $query = Db::name('fitness_enterprise')->alias('e')
            ->field('e.*, s.name AS store_name')
            ->join('fitness_store s', 'e.store_id = s.id', 'LEFT');
        if ($deleted === 1) {
            $query->whereNotNull('e.delete_time');
        } else {
            $query->whereNull('e.delete_time');
        }
        if ($keyword !== '') {
            $query->where('e.name|e.guild_name', 'like', '%' . $keyword . '%');
        }
        if ($storeId > 0) {
            $query->where('e.store_id', $storeId);
        }

        $rows = $query->order('e.id', 'desc')->select()->toArray();
        $ids = array_column($rows, 'id');
        $studentCounts = [];
        if ($ids) {
            $studentCounts = Db::name('fitness_member')
                ->whereIn('enterprise_id', $ids)->whereNull('delete_time')
                ->group('enterprise_id')->column('COUNT(*)', 'enterprise_id');
        }

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['id'],
                $row['name'] ?? '',
                $row['guild_name'] ?? '',
                $row['store_name'] ?? '',
                (int) ($studentCounts[$row['id']] ?? 0),
                $row['intro'] ?? '',
                $row['guild_intro'] ?? '',
                $row['delete_time'] ? '禁用' : '启用',
                $row['create_time'] ?? '',
            ];
        }

        return $this->success(FitnessExportService::payload(
            '企业列表_' . date('Ymd_His') . '.csv',
            ['ID', '企业名称', '公会名称', '门店', '学员数', '企业介绍', '公会介绍', '状态', '创建时间'],
            $data
        ));
    }
}
