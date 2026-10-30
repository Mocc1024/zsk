<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Attachment;
use app\service\AttachmentFolderService;
use app\service\AttachmentService;
use think\facade\Db;

/**
 * 素材库：列表 / 编辑 / 删除
 */
class AttachmentController extends BaseController
{
    /**
     * 列表（分页 + 筛选）
     */
    public function index()
    {
        $this->checkPermission('attachment.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = Db::name('attachment')->whereNull('delete_time');

        $type = trim((string) ($params['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        $folder = trim((string) ($params['folder'] ?? ''));
        if ($folder !== '') {
            $query->where('folder', $folder);
        }

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('name|alt', 'like', '%' . $keyword . '%');
        }

        $start = trim((string) ($params['start_date'] ?? ''));
        if ($start !== '') {
            $query->where('create_time', '>=', $start . ' 00:00:00');
        }
        $end = trim((string) ($params['end_date'] ?? ''));
        if ($end !== '') {
            $query->where('create_time', '<=', $end . ' 23:59:59');
        }

        $total = (int) (clone $query)->count();

        $list = $query
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
     * 编辑（名称 / 分组 / 替代文本）
     */
    public function update($id = 0)
    {
        $this->checkPermission('attachment.update');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = Attachment::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('素材不存在或已删除', 404);
        }

        $data = $this->params();
        $payload = [];
        foreach (['name', 'folder', 'alt'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = trim((string) $data[$field]);
            }
        }
        if (!$payload) {
            return $this->fail('没有需要更新的内容');
        }
        // 分组允许直接填写新名字：不存在时自动登记为分组
        if (isset($payload['folder'])) {
            $payload['folder'] = AttachmentFolderService::ensure($payload['folder']);
        }
        $payload['update_time'] = date('Y-m-d H:i:s');

        $row->save($payload);

        return $this->success(null, '保存成功');
    }

    /**
     * 删除（被引用时拒绝）
     */
    public function delete($id = 0)
    {
        $this->checkPermission('attachment.delete');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = Attachment::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('素材不存在或已删除', 404);
        }

        if ((int) $row->getAttr('ref_count') > 0) {
            $titles = array_column(AttachmentService::refContents($id), 'title');
            $text   = $titles ? '（' . implode('、', $titles) . '）' : '';

            return $this->fail('该素材正在被 ' . (int) $row->getAttr('ref_count') . ' 篇内容引用，无法删除' . $text);
        }

        AttachmentService::softDelete($id);
        $this->log('attachment', 'delete', '删除素材：' . $row->getAttr('name'));

        return $this->success(null, '删除成功');
    }

    /**
     * 批量删除：跳过被引用项并回报结果
     */
    public function batchDelete()
    {
        $this->checkPermission('attachment.delete');

        $data = $this->params();
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return $this->fail('请先选择要删除的素材');
        }

        $rows = Db::name('attachment')
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->select()
            ->toArray();

        $deleted = 0;
        $skipped = [];
        foreach ($rows as $row) {
            if ((int) $row['ref_count'] > 0) {
                $skipped[] = $row['name'];
                continue;
            }
            AttachmentService::softDelete((int) $row['id']);
            $deleted++;
        }

        $this->log('attachment', 'batch-delete', '批量删除素材 ' . $deleted . ' 个');

        return $this->success([
            'deleted' => $deleted,
            'skipped' => $skipped,
        ], $skipped
            ? '已删除 ' . $deleted . ' 个，' . count($skipped) . ' 个因被引用而跳过'
            : '已删除 ' . $deleted . ' 个素材');
    }

    /**
     * 批量移动素材到指定分组（分组不存在时自动创建）
     */
    public function move()
    {
        $this->checkPermission('attachment.update');

        $data   = $this->params();
        $ids    = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = explode(',', (string) $ids);
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return $this->fail('请先选择要移动的素材');
        }

        $folder = AttachmentFolderService::ensure((string) ($data['folder'] ?? ''));

        $count = Db::name('attachment')
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->update([
                'folder'      => $folder,
                'update_time' => date('Y-m-d H:i:s'),
            ]);

        $this->log('attachment', 'move', '将 ' . $count . ' 个素材移动到分组：' . $folder);

        return $this->success(['count' => $count, 'folder' => $folder], '已移动 ' . $count . ' 个素材到「' . $folder . '」');
    }
}
