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
    /** 系统内置的初始超级管理员：不可禁用 / 删除 / 变更所属用户组 */
    public const BUILTIN_SUPER_ADMIN_ID = 1;

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

        // 用模型查询：group_title / group_names / status_text 会自动附加
        $query = Admin::whereNull('delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('username|nickname|email', 'like', '%' . $keyword . '%');
        }
        if (($params['group_id'] ?? '') !== '' && $params['group_id'] !== null) {
            $query->where('group_id', (int) $params['group_id']);
        }
        if (($params['status'] ?? '') !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->field('id,username,nickname,avatar,group_id,email,status,last_login_ip,last_login_time,create_time,update_time')
            ->order('id', 'asc')->page($page, $limit)->select()->toArray();

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
            'group_id' => 'require|gt:0',
        ], [
            'username.require'   => '请输入登录账号',
            'username.alphaDash' => '账号只能包含字母、数字、下划线',
            'password.require'   => '请输入登录密码',
            'password.length'    => '密码长度需在 6-32 位之间',
            'nickname.require'   => '请输入昵称',
            'group_id.require'   => '请选择所属用户组',
            'group_id.gt'        => '请选择所属用户组',
        ]);

        // 查重排除已删除账号，避免同名账号无法复用
        if (Admin::where('username', $data['username'])->whereNull('delete_time')->count() > 0) {
            return $this->fail('该登录账号已存在');
        }

        // 所选用户组必须存在且启用，否则该账号没有任何权限、等于无法使用
        $groupId = (int) ($data['group_id'] ?? 0);
        if (!$this->groupExists($groupId)) {
            return $this->fail('所选用户组不存在或已禁用');
        }

        $model = new Admin();
        $model->save([
            'username'    => trim((string) $data['username']),
            'password'    => (string) $data['password'],
            'nickname'    => trim((string) ($data['nickname'] ?? '')),
            'email'       => trim((string) ($data['email'] ?? '')),
            'avatar'      => trim((string) ($data['avatar'] ?? '')),
            'group_id'    => $groupId,
            'status'      => (int) ($data['status'] ?? 1) === 1 ? Admin::STATUS_ENABLE : Admin::STATUS_DISABLE,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        self::attachGroup((int) $model->getAttr('id'), $groupId);
        Permission::flush();
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
        foreach (['nickname', 'email', 'avatar', 'group_id', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = in_array($field, ['status', 'group_id'], true)
                    ? (int) $data[$field]
                    : trim((string) $data[$field]);
            }
        }
        if (!empty($data['password'])) {
            if (strlen((string) $data['password']) < 6) {
                return $this->fail('密码长度不能少于 6 位');
            }
            $payload['password'] = (string) $data['password'];
        }

        // 内置初始超级管理员：不可禁用，且固定归属超级管理员组
        if ($id === self::BUILTIN_SUPER_ADMIN_ID) {
            unset($payload['status'], $payload['group_id']);
        }

        // 禁止禁用自己（防止把自己锁死）
        if ($id === Context::adminId()) {
            unset($payload['status']);
        }

        // 变更所属组时必须指向一个有效且启用的用户组
        if (array_key_exists('group_id', $payload) && !$this->groupExists((int) $payload['group_id'])) {
            return $this->fail('所选用户组不存在或已禁用');
        }

        // 禁止把自己移出超级管理员组，否则会立刻失去管理后台的能力
        if (
            $id === Context::adminId()
            && array_key_exists('group_id', $payload)
            && Permission::isSuper($row->toArray())
            && !Permission::groupsAreSuper([(int) $payload['group_id']])
        ) {
            return $this->fail('不能将自己移出超级管理员组，否则将无法继续管理后台');
        }

        if (empty($payload)) {
            return $this->success(null, '没有需要更新的数据');
        }
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);

        if (isset($payload['group_id'])) {
            // 主组变更即重置归属：先清掉旧的附加组，再加入新组
            Db::name('auth_group_access')->where('uid', $id)->delete();
            self::attachGroup($id, (int) $payload['group_id']);
        }
        Permission::flush();

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
        if ($id === self::BUILTIN_SUPER_ADMIN_ID) {
            return $this->fail('系统内置的初始超级管理员不可删除');
        }

        if ($id === Context::adminId()) {
            return $this->fail('不能删除当前登录的账号');
        }

        $row = Admin::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('管理员不存在', 404);
        }

        $row->save(['delete_time' => date('Y-m-d H:i:s')]);
        // 同步清理用户组关联，避免软删后仍占用组成员名额
        Db::name('auth_group_access')->where('uid', $id)->delete();
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

        if ($id === self::BUILTIN_SUPER_ADMIN_ID) {
            return $this->fail('系统内置的初始超级管理员不可禁用');
        }

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
     * 把管理员加入指定用户组（幂等）
     */
    protected static function attachGroup(int $uid, int $groupId): void
    {
        if ($uid <= 0 || $groupId <= 0) {
            return;
        }
        Db::execute(
            'INSERT IGNORE INTO `zsk_auth_group_access` (`uid`, `group_id`) VALUES (?, ?)',
            [$uid, $groupId]
        );
    }

    /**
     * 用户组是否存在且处于启用状态
     *
     * 管理员的权限完全来自所属用户组，挂到不存在或已禁用的组上等于没有任何权限。
     */
    protected function groupExists(int $groupId): bool
    {
        return $groupId > 0
            && Db::name('auth_group')
                ->where('id', $groupId)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->count() > 0;
    }
}
