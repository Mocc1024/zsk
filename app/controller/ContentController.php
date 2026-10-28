<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Content;
use app\service\Permission;
use think\facade\Db;

/**
 * 内容管理：列表 / 新增 / 编辑 / 删除
 */
class ContentController extends BaseController
{
    /**
     * 可写入字段白名单
     */
    protected function editableFields(): array
    {
        return ['category_id', 'title', 'author', 'cover', 'tags', 'summary', 'content', 'status', 'is_top', 'views', 'sort', 'publish_time'];
    }

    /**
     * 列表（分页 + 搜索 + 排序）
     */
    public function index()
    {
        $this->checkPermission('content.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = Db::name('content')
            ->alias('c')
            ->field('c.*, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('c.title|c.author|c.summary|c.tags', 'like', '%' . $keyword . '%');
        }

        if (($params['category_id'] ?? '') !== '' && $params['category_id'] !== null) {
            $query->where('c.category_id', (int) $params['category_id']);
        }

        if (($params['status'] ?? '') !== '' && $params['status'] !== null) {
            $query->where('c.status', (int) $params['status']);
        }

        if (($params['is_top'] ?? '') !== '' && $params['is_top'] !== null) {
            $query->where('c.is_top', (int) $params['is_top']);
        }

        $start = trim((string) ($params['start_date'] ?? ''));
        if ($start !== '') {
            $query->where('c.create_time', '>=', $start . ' 00:00:00');
        }
        $end = trim((string) ($params['end_date'] ?? ''));
        if ($end !== '') {
            $query->where('c.create_time', '<=', $end . ' 23:59:59');
        }

        $sortField = (string) ($params['sort_field'] ?? 'c.id');
        $sortOrder = strtolower((string) ($params['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!in_array($sortField, ['c.id', 'c.sort', 'c.views', 'c.create_time', 'c.publish_time'], true)) {
            $sortField = 'c.id';
        }

        $total = (int) (clone $query)->count();

        $list = $query
            ->order('c.is_top', 'desc')
            ->order($sortField, $sortOrder)
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 补充状态文本
        $statusMap = Content::statusMap();
        foreach ($list as &$item) {
            $item['status_text']   = $statusMap[(int) $item['status']] ?? '未知';
            $item['category_name'] = $item['category_name'] ?? '未分类';
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
     * 详情
     */
    public function read($id = 0)
    {
        $this->checkPermission('content.list');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $data = $row->toArray();
        $data['category_name'] = (string) Db::name('category')->where('id', (int) $data['category_id'])->value('name');

        return $this->success($data);
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('content.create');

        $data = $this->params();
        $this->validateContent($data);

        // 新建时没有对应权限 → 强制存为草稿、不置顶
        if (!Permission::can('content.status')) {
            $data['status'] = Content::STATUS_DRAFT;
        }
        if (!Permission::can('content.top')) {
            $data['is_top'] = 0;
        }

        $payload = $this->buildPayload($data);
        $payload['create_time'] = date('Y-m-d H:i:s');
        $payload['update_time'] = date('Y-m-d H:i:s');

        $model = new Content();
        $model->save($payload);

        $this->log('content', 'create', '新增内容：' . $payload['title']);

        return $this->success(['id' => $model->getAttr('id')], '新增成功');
    }

    /**
     * 更新
     */
    public function update($id = 0)
    {
        $this->checkPermission('content.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $this->validateContent($data);

        // 敏感字段越权防护：改动「发布状态」「是否置顶」需要各自的权限点
        $old = $row->toArray();
        $this->checkFieldChange($data, $old, 'status', 'content.status', '发布状态');
        $this->checkFieldChange($data, $old, 'is_top', 'content.top', '置顶状态');

        $payload = $this->buildPayload($data);
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);

        $this->log('content', 'update', '编辑内容：' . ($payload['title'] ?? $row->getAttr('title')));

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 删除（软删除）
     */
    public function delete($id = 0)
    {
        $this->checkPermission('content.delete');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $title = $row->getAttr('title');
        $row->save(['delete_time' => date('Y-m-d H:i:s')]);

        $this->log('content', 'delete', '删除内容：' . $title);

        return $this->success(null, '删除成功');
    }

    /**
     * 批量删除
     */
    public function batchDelete()
    {
        $this->checkPermission('content.batch-delete');

        $data = $this->params();
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return $this->fail('请先选择要删除的内容');
        }

        $count = Db::name('content')
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->update(['delete_time' => date('Y-m-d H:i:s')]);

        $this->log('content', 'batch-delete', '批量删除内容 ' . count($ids) . ' 条');

        return $this->success(['count' => $count], '已删除 ' . $count . ' 条内容');
    }

    /**
     * 修改状态
     */
    public function setStatus($id = 0)
    {
        $this->checkPermission('content.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $data   = $this->params();
        $status = (int) ($data['status'] ?? 0);

        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $payload = [
            'status'      => $status,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        if ($status === Content::STATUS_PUBLISHED && empty($row->getAttr('publish_time'))) {
            $payload['publish_time'] = date('Y-m-d H:i:s');
        }
        $row->save($payload);

        $text = Content::statusMap()[$status] ?? '未知';
        $this->log('content', 'status', '将内容「' . $row->getAttr('title') . '」设为' . $text);

        return $this->success(null, '状态已更新为' . $text);
    }

    /**
     * 置顶 / 取消置顶
     */
    public function setTop($id = 0)
    {
        $this->checkPermission('content.top');

        $id    = (int) ($id ?: $this->request->param('id'));
        $data  = $this->params();
        $isTop = (int) ($data['is_top'] ?? 0) === 1 ? 1 : 0;

        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $row->save(['is_top' => $isTop, 'update_time' => date('Y-m-d H:i:s')]);

        $this->log('content', 'top', ($isTop ? '置顶' : '取消置顶') . '内容：' . $row->getAttr('title'));

        return $this->success(null, $isTop ? '已置顶' : '已取消置顶');
    }

    /**
     * 内容表单校验
     *
     * 没有「发布/下架」权限的人，不强制提交 status：
     * 该字段会被从写入数据中剔除，保持原值，而不是直接报错。
     */
    private function validateContent(array $data): void
    {
        $rules = [
            'title'       => 'require|max:200',
            'category_id' => 'require|integer|egt:0',
        ];
        $messages = [
            'title.require'       => '请输入内容标题',
            'category_id.require' => '请选择内容分类',
        ];

        if (Permission::can('content.status')) {
            $rules['status']            = 'require|in:0,1,2';
            $messages['status.require'] = '请选择发布状态';
        }

        $this->validate($data, $rules, $messages);
    }

    /**
     * 构建写入数据
     */
    protected function buildPayload(array $data): array
    {
        $payload = [];
        foreach ($this->editableFields() as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['category_id', 'status', 'is_top', 'views', 'sort'], true)) {
                $value = (int) $value;
            } else {
                $value = is_string($value) ? trim($value) : $value;
            }
            $payload[$field] = $value;
        }

        // 发布状态自动补充发布时间
        if (($payload['status'] ?? null) === Content::STATUS_PUBLISHED && empty($payload['publish_time'])) {
            $payload['publish_time'] = date('Y-m-d H:i:s');
        }
        if (!empty($payload['publish_time'])) {
            $payload['publish_time'] = date('Y-m-d H:i:s', strtotime((string) $payload['publish_time']));
        } else {
            $payload['publish_time'] = null;
        }

        return $payload;
    }
}
