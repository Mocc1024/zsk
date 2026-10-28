<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Admin;
use app\service\Context;
use app\service\Permission;
use think\facade\Db;

/**
 * 管理员管理（仅超级管理员可用）
 */
class AdminController extends BaseController
{
    protected function initialize()
    {
        if (!Context::isSuper()) {
            throw new \think\exception\HttpException(403, '仅超级管理员可访问该模块');
        }
    }

    /**
     * 列表
     */
    public function index()
    {
        $this->checkPermission('admin.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        // 用模型查询，permissions 字段会自动从 JSON 转成数组
        $query = Admin::whereNull('delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('username|nickname|email', 'like', '%' . $keyword . '%');
        }
        if (($params['role'] ?? '') !== '') {
            $query->where('role', (string) $params['role']);
        }
        if (($params['status'] ?? '') !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->field('id,username,nickname,avatar,role,permissions,email,status,last_login_ip,last_login_time,create_time,update_time')
            ->order('id', 'asc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['role_text']   = $item['role'] === Admin::ROLE_ADMIN ? '超级管理员' : '编辑';
            $item['status_text'] = (int) $item['status'] === Admin::STATUS_ENABLE ? '启用' : '禁用';
            // 超级管理员不读 permissions 字段，代码里直接赋予全部权限
            $item['permissions'] = Permission::forAdmin($item);
        }
        unset($item);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('admin.create');

        $data = $this->params();
        $this->validate($data, [
            'username' => 'require|alphaDash|length:3,30',
            'password' => 'require|length:6,32',
            'nickname' => 'require|max:50',
        ], [
            'username.require'   => '请输入登录账号',
            'username.alphaDash' => '账号只能包含字母、数字、下划线',
            'password.require'   => '请输入登录密码',
            'password.length'    => '密码长度需在 6-32 位之间',
            'nickname.require'   => '请输入昵称',
        ]);

        if (Admin::where('username', $data['username'])->count() > 0) {
            return $this->fail('该登录账号已存在');
        }

        $role = ($data['role'] ?? '') === Admin::ROLE_ADMIN ? Admin::ROLE_ADMIN : Admin::ROLE_EDITOR;

        $model = new Admin();
        $model->save([
            'username'    => trim((string) $data['username']),
            'password'    => (string) $data['password'],
            'nickname'    => trim((string) ($data['nickname'] ?? '')),
            'email'       => trim((string) ($data['email'] ?? '')),
            'avatar'      => trim((string) ($data['avatar'] ?? '')),
            'role'        => $role,
            // 超级管理员该字段留空，鉴权时直接放行全部权限
            'permissions' => Permission::toStorage(['role' => $role], $data['permissions'] ?? []),
            'status'      => (int) ($data['status'] ?? 1) === 1 ? Admin::STATUS_ENABLE : Admin::STATUS_DISABLE,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('admin', 'create', '新增管理员：' . $data['username']);

        return $this->success(['id' => $model->getAttr('id')], '新增成功');
    }

    /**
     * 更新
     */
    public function update($id = 0)
    {
        $this->checkPermission('admin.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = Admin::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('管理员不存在', 404);
        }

        $payload = [];
        foreach (['nickname', 'email', 'avatar', 'role', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = in_array($field, ['status'], true) ? (int) $data[$field] : trim((string) $data[$field]);
            }
        }
        if (!empty($data['password'])) {
            if (strlen((string) $data['password']) < 6) {
                return $this->fail('密码长度不能少于 6 位');
            }
            $payload['password'] = (string) $data['password'];
        }

        if (array_key_exists('permissions', $data)) {
            // 角色以本次提交的为准，未提交则用库里的角色
            $targetRole = (string) ($data['role'] ?? $row->getAttr('role'));
            $payload['permissions'] = Permission::toStorage(['role' => $targetRole], $data['permissions']);
        }

        // 禁止禁用/降级自己，也不允许改自己的权限（防止把自己锁死）
        if ($id === Context::adminId()) {
            unset($payload['status'], $payload['role'], $payload['permissions']);
        }

        if (empty($payload)) {
            return $this->success(null, '没有需要更新的数据');
        }
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);

        $this->log('admin', 'update', '编辑管理员：' . $row->getAttr('username'));

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除
     */
    public function delete($id = 0)
    {
        $this->checkPermission('admin.delete');

        $id = (int) ($id ?: $this->request->param('id'));
        if ($id === Context::adminId()) {
            return $this->fail('不能删除当前登录的账号');
        }

        $row = Admin::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('管理员不存在', 404);
        }
        if ($row->getAttr('id') === 1) {
            return $this->fail('初始超级管理员不可删除');
        }

        $row->save(['delete_time' => date('Y-m-d H:i:s')]);
        $this->log('admin', 'delete', '删除管理员：' . $row->getAttr('username'));

        return $this->success(null, '删除成功');
    }

    /**
     * 启用 / 禁用
     */
    public function setStatus($id = 0)
    {
        $this->checkPermission('admin.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $data   = $this->params();
        $status = (int) ($data['status'] ?? 0) === 1 ? 1 : 0;

        if ($id === Context::adminId()) {
            return $this->fail('不能禁用当前登录的账号');
        }

        $row = Admin::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('管理员不存在', 404);
        }

        $row->save(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('admin', 'status', ($status ? '启用' : '禁用') . '管理员：' . $row->getAttr('username'));

        return $this->success(null, $status ? '已启用' : '已禁用');
    }

    /**
     * 角色选项
     */
    public function roleOptions()
    {
        return $this->success(Admin::roleOptions());
    }

    /**
     * 权限树（后台勾选分配用）
     *
     * 返回 config/permission.php 定义的完整树 + 所有叶子权限 key。
     * 超级管理员不依赖勾选结果，始终拥有全部权限。
     */
    public function permissionTree()
    {
        $this->checkPermission('admin.list');

        return $this->success([
            'tree'    => Permission::tree(),
            'allKeys' => Permission::allKeys(),
        ]);
    }

    /**
     * 查看某个管理员已分配的权限
     */
    public function adminPermissions($id = 0)
    {
        $this->checkPermission('admin.list');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = Admin::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('管理员不存在', 404);
        }

        return $this->success([
            'id'          => $id,
            'role'        => $row->getAttr('role'),
            'isSuper'     => $row->getAttr('role') === Admin::ROLE_ADMIN,
            'permissions' => Permission::forAdmin($row->toArray()),
        ]);
    }
}
