<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Category;
use app\service\Permission;
use think\facade\Db;

/**
 * 分类管理
 */
class CategoryController extends BaseController
{
    /**
     * 列表（分页）
     */
    public function index()
    {
        $this->checkPermission('category.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = Db::name('category')->whereNull('delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('name|slug|description', 'like', '%' . $keyword . '%');
        }
        if (($params['status'] ?? '') !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('sort', 'desc')->order('id', 'desc')
            ->page($page, $limit)->select()->toArray();

        // 分类下的内容数量
        $counts = Db::name('content')
            ->whereNull('delete_time')
            ->group('category_id')
            ->column('COUNT(*)', 'category_id');

        foreach ($list as &$item) {
            $item['content_count'] = (int) ($counts[$item['id']] ?? 0);
            $item['status_text']   = (int) $item['status'] === Category::STATUS_ENABLE ? '启用' : '禁用';
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
     * 下拉选项
     *
     * 默认只返回「启用」分类（供内容列表筛选用）。
     *
     * 编辑内容时传 include_disabled=1 取全部：禁用分类由前端置灰、不允许选择，
     * 但必须出现在下拉里 —— 否则「内容当前所属的禁用分类」在 options 中找不到对应项，
     * 下拉会回显成空白或裸 id，用户一保存就可能把分类改掉。
     */
    public function options()
    {
        $params = $this->params();
        $includeDisabled = (int) ($params['include_disabled'] ?? 0) === 1;

        $query = Db::name('category')->whereNull('delete_time');
        if (!$includeDisabled) {
            $query->where('status', Category::STATUS_ENABLE);
        }

        $list = $query->order('sort', 'desc')->order('id', 'desc')
            ->select()
            ->toArray();

        return $this->success($list);
    }

    /**
     * 详情
     */
    public function read($id = 0)
    {
        $this->checkPermission('category.list');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Category::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('分类不存在或已删除', 404);
        }
        return $this->success($row->toArray());
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('category.create');

        $data = $this->params();
        $this->validate($data, [
            'name' => 'require|max:60',
        ], [
            'name.require' => '请输入分类名称',
        ]);

        // 新建时没有启用/禁用权限 → 一律存为禁用，待有权限的人确认后开启
        if (!Permission::can('category.status')) {
            $data['status'] = Category::STATUS_DISABLE;
        }

        $payload = $this->buildPayload($data);
        $payload['create_time'] = date('Y-m-d H:i:s');
        $payload['update_time'] = date('Y-m-d H:i:s');

        $model = new Category();
        $model->save($payload);

        $this->log('category', 'create', '新增分类：' . $payload['name']);

        return $this->success(['id' => $model->getAttr('id')], '新增成功');
    }

    /**
     * 更新
     */
    public function update($id = 0)
    {
        $this->checkPermission('category.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = Category::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('分类不存在或已删除', 404);
        }

        $this->validate($data, [
            'name' => 'require|max:60',
        ], [
            'name.require' => '请输入分类名称',
        ]);

        // 敏感字段越权防护：改动「启用状态」需要 category.status 权限
        $this->checkFieldChange($data, $row->toArray(), 'status', 'category.status', '启用状态');

        $payload = $this->buildPayload($data);
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);

        $this->log('category', 'update', '编辑分类：' . ($payload['name'] ?? ''));

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除（软删除）
     */
    public function delete($id = 0)
    {
        $this->checkPermission('category.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Category::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('分类不存在或已删除', 404);
        }

        $used = (int) Db::name('content')->where('category_id', $id)->whereNull('delete_time')->count();
        if ($used > 0) {
            // 文案与前端禁用按钮的 Tooltip 保持一致，避免两处说法不一
            return $this->fail('该分类下还有 ' . $used . ' 条内容，请先移走或删除后再删除该分类');
        }

        $row->save(['delete_time' => date('Y-m-d H:i:s')]);
        $this->log('category', 'delete', '删除分类：' . $row->getAttr('name'));

        return $this->success(null, '删除成功');
    }

    /**
     * 启用 / 禁用
     */
    public function setStatus($id = 0)
    {
        $this->checkPermission('category.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $data   = $this->params();
        $status = (int) ($data['status'] ?? 0) === 1 ? 1 : 0;

        $row = Category::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('分类不存在或已删除', 404);
        }

        $row->save(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('category', 'status', ($status ? '启用' : '禁用') . '分类：' . $row->getAttr('name'));

        return $this->success(null, $status ? '已启用' : '已禁用');
    }

    protected function buildPayload(array $data): array
    {
        $payload = [];
        foreach (['name', 'slug', 'description', 'sort', 'status'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['sort', 'status'], true)) {
                $value = (int) $value;
            } else {
                $value = is_string($value) ? trim($value) : $value;
            }
            $payload[$field] = $value;
        }
        if (empty($payload['slug']) && !empty($payload['name'])) {
            $payload['slug'] = 'cat_' . substr(md5($payload['name'] . microtime(true)), 0, 10);
        }
        return $payload;
    }
}
