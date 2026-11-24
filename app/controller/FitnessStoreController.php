<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\FitnessService;
use think\facade\Db;

/**
 * 全民健身 - 门店管理
 */
class FitnessStoreController extends BaseController
{
    /** 下拉选项（供其他模块绑定用） */
    public function options()
    {
        $this->checkPermission('fitness.store.list');
        $list = Db::name('fitness_store')
            ->whereNull('delete_time')->where('status', 1)
            ->field('id,name')->order('sort', 'desc')->order('id', 'asc')->select()->toArray();
        return $this->success($list);
    }

    /** 列表（含该门店实时指标，供切换门店后卡片展示） */
    public function index()
    {
        $this->checkPermission('fitness.store.list');

        [$page, $limit] = $this->pageInfo();
        $keyword = trim((string) $this->input('keyword', ''));
        $deleted = (int) $this->input('deleted', 0);

        $query = Db::name('fitness_store');
        if ($deleted === 1) {
            $query->whereNotNull('delete_time');
        } else {
            $query->whereNull('delete_time');
        }
        if ($keyword !== '') {
            $query->where('name|address', 'like', '%' . $keyword . '%');
        }
        $total = (int) (clone $query)->count();
        $list  = $query->order('sort', 'desc')->order('id', 'asc')
            ->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $stats = FitnessService::storeStats((int) $item['id']);
            $item['metrics'] = $stats[0] ?? null;
        }
        unset($item);

        return $this->success([
            'list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $limit,
        ]);
    }

    /** 新增 */
    public function save()
    {
        $this->checkPermission('fitness.store.create');

        $data = $this->params();
        $this->validate($data, [
            'name' => 'require|max:100',
        ], ['name.require' => '请输入门店名称']);

        $id = Db::name('fitness_store')->insertGetId([
            'name'        => trim((string) $data['name']),
            'address'     => trim((string) ($data['address'] ?? '')),
            'status'      => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'sort'        => (int) ($data['sort'] ?? 0),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('fitness.store', 'create', '新增门店：' . $data['name']);
        return $this->success(['id' => (int) $id], '新增成功');
    }

    /** 编辑 */
    public function update($id = 0)
    {
        $this->checkPermission('fitness.store.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $this->validate($data, [
            'name' => 'require|max:100',
        ], ['name.require' => '请输入门店名称']);

        $row = Db::name('fitness_store')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('门店不存在或已删除', 404);
        }

        Db::name('fitness_store')->where('id', $id)->update([
            'name'        => trim((string) $data['name']),
            'address'     => trim((string) ($data['address'] ?? '')),
            'status'      => array_key_exists('status', $data) ? ((int) $data['status'] === 1 ? 1 : 0) : (int) $row['status'],
            'sort'        => (int) ($data['sort'] ?? $row['sort']),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('fitness.store', 'update', '编辑门店 #' . $id);
        return $this->success(null, '保存成功');
    }

    /** 删除（有关联档案时拒绝） */
    public function delete($id = 0)
    {
        $this->checkPermission('fitness.store.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_store')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('门店不存在或已删除', 404);
        }

        $usage = FitnessService::storeUsage($id);
        $parts = [];
        if ($usage['member'] > 0)    { $parts[] = '会员 ' . $usage['member']; }
        if ($usage['coach'] > 0)     { $parts[] = '教练 ' . $usage['coach']; }
        if ($usage['enterprise'] > 0){ $parts[] = '企业 ' . $usage['enterprise']; }
        if ($parts) {
            return $this->fail('该门店下尚有 ' . implode('、', $parts) . '，请先移走后再删除');
        }

        Db::name('fitness_store')->where('id', $id)->update([
            'delete_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('fitness.store', 'delete', '删除门店 #' . $id . '：' . $row['name']);
        return $this->success(null, '删除成功');
    }

    /** 恢复已删除（禁用）的门店 */
    public function restore($id = 0)
    {
        $this->checkPermission('fitness.store.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('fitness_store')->where('id', $id)->whereNotNull('delete_time')->find();
        if (!$row) {
            return $this->fail('门店不存在或未禁用', 404);
        }

        Db::name('fitness_store')->where('id', $id)->update([
            'delete_time' => null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('fitness.store', 'restore', '恢复门店 #' . $id . '：' . $row['name']);
        return $this->success(null, '恢复成功');
    }
}
