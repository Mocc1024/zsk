<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\AuthGroup;
use app\service\Context;
use app\service\Permission;
use think\facade\Db;

/**
 * 用户组管理
 */
class AuthGroupController extends BaseController
{
    /** 系统内置超级管理员组：默认拥有全部权限，不可改权限 / 禁用 / 删除 */
    public const BUILTIN_SUPER_GROUP_ID = 1;

    /**
     * 列表
     */
    public function index()
    {
        $this->checkPermission('group.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = AuthGroup::whereNull('delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('title|description', 'like', '%' . $keyword . '%');
        }
        if (($params['status'] ?? '') !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('sort', 'desc')->order('id', 'asc')
            ->page($page, $limit)->select()->toArray();

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 下拉选项
     */
    public function options()
    {
        $list = AuthGroup::where('status', 1)->whereNull('delete_time')
            ->field('id,title')
            ->order('sort', 'desc')->order('id', 'asc')
            ->select()->toArray();

        return $this->success(array_map(static fn ($item) => [
            'label' => $item['title'],
            'value' => $item['id'],
        ], $list));
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('group.create');

        $data = $this->params();
        $this->validate($data, [
            'title' => 'require|max:60',
        ], [
            'title.require' => '请输入用户组名称',
        ]);

        if (AuthGroup::where('title', $data['title'])->whereNull('delete_time')->count() > 0) {
            return $this->fail('该用户组名称已存在');
        }

        $model = new AuthGroup();
        $model->save([
            'title'       => trim((string) $data['title']),
            'description' => trim((string) ($data['description'] ?? '')),
            'rules'       => self::normalizeRules($data['rules'] ?? ''),
            'sort'        => (int) ($data['sort'] ?? 0),
            'status'      => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        Permission::flush();
        $this->log('group', 'create', '新增用户组：' . $data['title']);

        return $this->success(['id' => $model->getAttr('id')], '新增成功');
    }

    /**
     * 更新
     */
    public function update($id = 0)
    {
        $this->checkPermission('group.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = AuthGroup::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('用户组不存在', 404);
        }

        $payload = [];
        foreach (['title', 'description', 'sort', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = in_array($field, ['sort', 'status'], true)
                    ? (int) $data[$field]
                    : trim((string) $data[$field]);
            }
        }

        // 系统内置超级管理员组不可禁用
        if ($id === self::BUILTIN_SUPER_GROUP_ID && isset($payload['status']) && (int) $payload['status'] === 0) {
            unset($payload['status']);
        }

        if (empty($payload)) {
            return $this->success(null, '没有需要更新的数据');
        }
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);
        Permission::flush();

        $this->log('group', 'update', '编辑用户组：' . $row->getAttr('title'));

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除
     */
    public function delete($id = 0)
    {
        $this->checkPermission('group.delete');

        $id = (int) ($id ?: $this->request->param('id'));
        if ($id === self::BUILTIN_SUPER_GROUP_ID) {
            return $this->fail('系统内置超级管理员组不可删除');
        }

        $row = AuthGroup::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('用户组不存在', 404);
        }

        $members = (int) Db::name('auth_group_access')->where('group_id', $id)->count();
        if ($members > 0) {
            return $this->fail("该用户组下还有 {$members} 位成员，请先将成员移出");
        }

        $row->save(['delete_time' => date('Y-m-d H:i:s')]);
        Permission::flush();
        $this->log('group', 'delete', '删除用户组：' . $row->getAttr('title'));

        return $this->success(null, '删除成功');
    }

    /**
     * 启用 / 禁用
     */
    public function setStatus($id = 0)
    {
        $this->checkPermission('group.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $data   = $this->params();
        $status = (int) ($data['status'] ?? 0) === 1 ? 1 : 0;

        if ($id === self::BUILTIN_SUPER_GROUP_ID) {
            return $this->fail('系统内置超级管理员组不可禁用');
        }

        $row = AuthGroup::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('用户组不存在', 404);
        }

        $row->save(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        Permission::flush();
        $this->log('group', 'status', ($status ? '启用' : '禁用') . '用户组：' . $row->getAttr('title'));

        return $this->success(null, $status ? '已启用' : '已禁用');
    }

    /**
     * 查看某个用户组已分配的权限规则 ID
     */
    public function rules($id = 0)
    {
        $this->checkPermission('group.list');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = AuthGroup::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('用户组不存在', 404);
        }

        $raw = trim((string) $row->getAttr('rules'));

        return $this->success([
            'id'      => $id,
            'is_all'  => $raw === Permission::ALL,
            'rule_ids' => $raw === Permission::ALL ? [] : $row->rule_ids,
        ]);
    }

    /**
     * 分配权限
     */
    public function authorize($id = 0)
    {
        $this->checkPermission('group.authorize');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        // 内置超级管理员组默认拥有全部权限，不接受授权变更
        if ($id === self::BUILTIN_SUPER_GROUP_ID) {
            return $this->fail('内置超级管理员组默认拥有全部权限，无需分配');
        }

        $row = AuthGroup::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('用户组不存在', 404);
        }

        $newRules = self::normalizeRules($data['rules'] ?? '');
        $oldRules = trim((string) $row->getAttr('rules'));

        // 自我保护：不允许移除自己所属用户组的超级管理员权限，否则会立刻失去管理能力
        if (
            $oldRules === Permission::ALL
            && $newRules !== Permission::ALL
            && in_array($id, Permission::groupIdsOf(Context::admin()), true)
            && (int) Context::adminId() !== 1
        ) {
            return $this->fail('不能移除自己所属用户组的超级管理员权限，否则将无法继续管理后台');
        }

        $row->save([
            'rules'       => $newRules,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        Permission::flush();
        $this->log('group', 'authorize', '分配用户组权限：' . $row->getAttr('title'));

        return $this->success(null, '权限分配成功');
    }

    /**
     * 规范化权限规则：数组转逗号分隔，* 视为全部权限
     *
     * @param mixed $rules
     */
    protected static function normalizeRules($rules): string
    {
        if (is_string($rules)) {
            $rules = trim($rules);
            if ($rules === Permission::ALL) {
                return Permission::ALL;
            }
            $rules = array_filter(array_map('trim', explode(',', $rules)));
        }
        if (!is_array($rules)) {
            return '';
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $rules), static fn ($v) => $v > 0)));
        sort($ids, SORT_NUMERIC);

        return implode(',', $ids);
    }
}
