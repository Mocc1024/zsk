<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\AuthRule;
use app\service\Permission;
use think\facade\Db;

/**
 * 权限菜单管理
 */
class AuthRuleController extends BaseController
{
    /**
     * 权限树
     *
     * 两种用途返回的数据不同：
     *  - scope=manage：全部状态（含已禁用），供后台「权限菜单」页管理，
     *    否则一旦禁用就会从列表消失、再也找不到入口重新启用
     *  - 默认（授权场景）：仅启用项，禁用的权限点不允许再被勾选授权
     */
    public function index()
    {
        $this->checkPermission('permission.list');

        $manage = (string) $this->request->param('scope', '') === 'manage';

        return $this->success(['list' => $manage ? Permission::manageTree() : Permission::tree()]);
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('permission.create');

        $data = $this->params();
        $this->validate($data, [
            'title' => 'require|max:60',
            'type'  => 'require|in:1,2',
            'name' => 'max:100',
            'path' => 'max:150',
        ], [
            'title.require' => '请输入名称',
            'type.require'  => '请选择类型',
            'type.in'       => '类型只能是菜单或权限点',
        ]);

        $pid  = (int) ($data['pid'] ?? 0);
        $type = (int) ($data['type'] ?? AuthRule::TYPE_MENU);

        if ($pid > 0 && !AuthRule::where('id', $pid)->whereNull('delete_time')->find()) {
            return $this->fail('上级菜单不存在', 404);
        }
        // 权限点必须挂在菜单下
        if ($type === AuthRule::TYPE_ACTION && $pid === 0) {
            return $this->fail('权限点必须选择上级菜单');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($type === AuthRule::TYPE_ACTION) {
            if ($name === '') {
                return $this->fail('请输入权限标识');
            }
            if (AuthRule::where('name', $name)->whereNull('delete_time')->count() > 0) {
                return $this->fail('该权限标识已存在');
            }
        }

        $model = new AuthRule();
        $model->save([
            'pid'         => $pid,
            'type'        => $type,
            'title'       => trim((string) $data['title']),
            'name'        => $name,
            'path'        => trim((string) ($data['path'] ?? '')),
            'icon'        => trim((string) ($data['icon'] ?? '')),
            'sort'        => (int) ($data['sort'] ?? 0),
            'status'      => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        Permission::flush();
        $this->log('permission', 'create', '新增权限菜单：' . $data['title']);

        return $this->success(['id' => $model->getAttr('id')], '新增成功');
    }

    /**
     * 更新
     */
    public function update($id = 0)
    {
        $this->checkPermission('permission.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = AuthRule::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('权限菜单不存在', 404);
        }

        $payload = [];
        foreach (['pid', 'type', 'title', 'name', 'path', 'icon', 'sort', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = in_array($field, ['pid', 'type', 'sort', 'status'], true)
                    ? (int) $data[$field]
                    : trim((string) $data[$field]);
            }
        }

        // 校验上级
        if (isset($payload['pid'])) {
            if ((int) $payload['pid'] === $id) {
                return $this->fail('上级不能是自己');
            }
            if ((int) $payload['pid'] > 0 && !AuthRule::where('id', (int) $payload['pid'])->whereNull('delete_time')->find()) {
                return $this->fail('上级菜单不存在', 404);
            }
        }
        $type = (int) ($payload['type'] ?? $row->getAttr('type'));
        $pid  = (int) ($payload['pid'] ?? $row->getAttr('pid'));
        if ($type === AuthRule::TYPE_ACTION && $pid === 0) {
            return $this->fail('权限点必须选择上级菜单');
        }

        // 权限标识唯一性
        if ($type === AuthRule::TYPE_ACTION) {
            $name = (string) ($payload['name'] ?? $row->getAttr('name'));
            if ($name === '') {
                return $this->fail('请输入权限标识');
            }
            $exists = AuthRule::where('name', $name)
                ->where('id', '<>', $id)
                ->whereNull('delete_time')
                ->count();
            if ($exists > 0) {
                return $this->fail('该权限标识已存在');
            }
        }

        if (empty($payload)) {
            return $this->success(null, '没有需要更新的数据');
        }
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);
        Permission::flush();

        $this->log('permission', 'update', '编辑权限菜单：' . $row->getAttr('title'));

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除（含子级）
     */
    public function delete($id = 0)
    {
        $this->checkPermission('permission.delete');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = AuthRule::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('权限菜单不存在', 404);
        }

        $now  = date('Y-m-d H:i:s');
        $ids  = [$id];
        $walk = function (int $pid) use (&$walk, &$ids) {
            $children = AuthRule::where('pid', $pid)->whereNull('delete_time')->column('id');
            foreach ($children as $cid) {
                $ids[] = (int) $cid;
                $walk((int) $cid);
            }
        };
        $walk($id);

        Db::name('auth_rule')->whereIn('id', $ids)->update(['delete_time' => $now]);
        Permission::flush();
        $this->log('permission', 'delete', '删除权限菜单：' . $row->getAttr('title'));

        return $this->success(null, '删除成功');
    }

    /**
     * 启用 / 禁用
     */
    public function setStatus($id = 0)
    {
        $this->checkPermission('permission.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $data   = $this->params();
        $status = (int) ($data['status'] ?? 0) === 1 ? 1 : 0;

        $row = AuthRule::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('权限菜单不存在', 404);
        }

        $row->save(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        Permission::flush();
        $this->log('permission', 'status', ($status ? '启用' : '禁用') . '权限菜单：' . $row->getAttr('title'));

        return $this->success(null, $status ? '已启用' : '已禁用');
    }
}
