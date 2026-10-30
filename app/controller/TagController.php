<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Tag;
use think\facade\Db;

/**
 * 标签管理：列表 / 新增 / 编辑 / 删除
 */
class TagController extends BaseController
{
    /**
     * 列表（分页 + 搜索）
     */
    public function index()
    {
        $this->checkPermission('tag.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = Db::name('tag')->whereNull('delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('name|slug', 'like', '%' . $keyword . '%');
        }

        if (($params['status'] ?? '') !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $total = (int) (clone $query)->count();

        $list = $query
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 全部标签（下拉选项，编辑器与筛选器用）
     */
    public function options()
    {
        $this->checkPermission('tag.list');

        $list = Db::name('tag')
            ->whereNull('delete_time')
            ->where('status', 1)
            ->order('sort', 'desc')
            ->order('id', 'desc')
            ->field('id, name, count')
            ->select()
            ->toArray();

        // value 用标签名（编辑器可直接输入新标签），另附 id 供按 ID 筛选使用
        return $this->success(array_map(
            static fn (array $row): array => [
                'label' => (string) $row['name'],
                'value' => (string) $row['name'],
                'id'    => (int) $row['id'],
                'count' => (int) $row['count'],
            ],
            $list
        ));
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('tag.create');

        $data = $this->params();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->fail('请输入标签名');
        }

        if (Db::name('tag')->where('name', $name)->whereNull('delete_time')->count() > 0) {
            return $this->fail('该标签已存在');
        }

        $now = date('Y-m-d H:i:s');
        $id  = (int) Db::name('tag')->insertGetId([
            'name'        => mb_substr($name, 0, 20),
            'slug'        => trim((string) ($data['slug'] ?? '')),
            'count'       => 0,
            'sort'        => (int) ($data['sort'] ?? 0),
            'status'      => isset($data['status']) ? (int) $data['status'] : 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $this->log('tag', 'create', '新增标签：' . $name);

        return $this->success(['id' => $id], '新增成功');
    }

    /**
     * 编辑
     */
    public function update($id = 0)
    {
        $this->checkPermission('tag.update');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('tag')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('标签不存在', 404);
        }

        $data    = $this->params();
        $payload = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->fail('标签名不能为空');
            }
            if (Db::name('tag')->where('name', $name)->where('id', '<>', $id)->whereNull('delete_time')->count() > 0) {
                return $this->fail('该标签名已被占用');
            }
            $payload['name'] = mb_substr($name, 0, 20);
        }
        if (array_key_exists('slug', $data)) {
            $payload['slug'] = trim((string) $data['slug']);
        }
        if (array_key_exists('sort', $data)) {
            $payload['sort'] = (int) $data['sort'];
        }
        if (array_key_exists('status', $data)) {
            $payload['status'] = (int) $data['status'] === 1 ? 1 : 0;
        }

        if (!$payload) {
            return $this->fail('没有需要更新的内容');
        }

        $payload['update_time'] = date('Y-m-d H:i:s');
        Db::name('tag')->where('id', $id)->update($payload);

        $this->log('tag', 'update', '编辑标签：' . ($payload['name'] ?? $row['name']));

        return $this->success(null, '保存成功');
    }

    /**
     * 删除（被引用时拒绝）
     */
    public function delete($id = 0)
    {
        $this->checkPermission('tag.delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('tag')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('标签不存在', 404);
        }

        if ((int) $row['count'] > 0) {
            return $this->fail('该标签被 ' . (int) $row['count'] . ' 篇内容使用，无法删除');
        }

        $now = date('Y-m-d H:i:s');
        Db::name('tag')->where('id', $id)->update(['delete_time' => $now, 'update_time' => $now]);

        $this->log('tag', 'delete', '删除标签：' . $row['name']);

        return $this->success(null, '删除成功');
    }
}
