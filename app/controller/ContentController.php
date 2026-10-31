<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Content;
use app\service\AttachmentService;
use app\service\ContentImportExport;
use app\service\HtmlFilter;
use app\service\Permission;
use app\service\TagService;
use think\facade\Db;

/**
 * 内容管理：列表 / 新增 / 编辑 / 删除
 *
 * 第二阶段扩展：回收站（M2.3）、自动保存（M2.4）、
 * 标签 / 复制（M2.5）、导出（M2.6）、正文 XSS 净化（M2.2）
 *
 * 注：内容版本（原 M2.4 的一部分：版本列表 / 版本详情 / 回滚）已于 2026.10.30 下线删除，
 * 自动保存保留 —— 两者是独立能力，删版本不影响自动保存
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

        // 标签筛选：按标签 ID 走关联表
        // 注意：子查询里的表名不会自动补前缀，因此这里先取出 ID 再过滤
        $tagId = (int) ($params['tag_id'] ?? 0);
        if ($tagId > 0) {
            $taggedIds = array_map('intval', Db::name('content_tag')
                ->where('tag_id', $tagId)
                ->column('content_id'));

            $query->whereIn('c.id', $taggedIds ?: [0]);
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

        // 补充状态文本与标签
        $statusMap = Content::statusMap();
        $tagMap    = $list ? TagService::namesForContents(array_column($list, 'id')) : [];
        foreach ($list as &$item) {
            $item['status_text']   = $statusMap[(int) $item['status']] ?? '未知';
            $item['category_name'] = $item['category_name'] ?? '未分类';
            $item['tags']          = $tagMap[(int) $item['id']] ?? [];
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
        $data['tags']          = TagService::namesOf($id);

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

        $tagNames = $this->tagNames($data);
        $payload  = $this->buildPayload($data);
        $payload['create_time'] = date('Y-m-d H:i:s');
        $payload['update_time'] = date('Y-m-d H:i:s');

        $model = new Content();
        $model->save($payload);
        $id = (int) $model->getAttr('id');

        // 关联同步：标签、附件引用
        TagService::syncForContent($id, $tagNames);
        AttachmentService::syncForContent($id, (string) ($payload['content'] ?? ''), (string) ($payload['cover'] ?? ''));

        $this->log('content', 'create', '新增内容：' . $payload['title']);

        return $this->success(['id' => $id], '新增成功');
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

        $tagNames = $this->tagNames($data);
        $payload  = $this->buildPayload($data, $old);
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);

        // 关联同步：标签、附件引用
        $current = array_merge($old, $payload);
        // 只在调用方显式传入 tags 时同步，与 publish_time 保持同一口径：
        // 未传的字段保持原值，避免只改状态 / 发布时间这类局部更新把标签静默清空。
        // 传空数组表示「清空全部标签」，仍会照常清空。
        if (array_key_exists('tags', $data)) {
            TagService::syncForContent($id, $tagNames);
        }
        AttachmentService::syncForContent($id, (string) ($current['content'] ?? ''), (string) ($current['cover'] ?? ''));

        $this->log('content', 'update', '编辑内容：' . ($payload['title'] ?? $row->getAttr('title')));

        return $this->success(['id' => $id], '保存成功');
    }

    /**
     * 自动保存（M2.4）
     *
     * 自动保存不改动「发布状态」与「置顶」，避免绕过这两个字段的独立权限点。
     * 30 分钟内同一操作人的自动保存会合并为一条版本记录（ADR-004）。
     */
    public function autosave()
    {
        $this->checkPermission('content.update');

        $data = $this->params();
        $id   = (int) ($data['id'] ?? 0);

        // 首次自动保存：先建一条草稿，再在其上落自动保存版本
        if ($id <= 0) {
            $this->checkPermission('content.create');

            $title = trim((string) ($data['title'] ?? ''));
            $data['title']   = $title === '' ? '未命名草稿 ' . date('m-d H:i') : $title;
            $data['status']  = Content::STATUS_DRAFT;
            $data['is_top']  = 0;

            $tagNames = $this->tagNames($data);
            $payload  = $this->buildPayload($data);
            $payload['create_time'] = date('Y-m-d H:i:s');
            $payload['update_time'] = date('Y-m-d H:i:s');

            $model = new Content();
            $model->save($payload);
            $id = (int) $model->getAttr('id');

            TagService::syncForContent($id, $tagNames);
            AttachmentService::syncForContent($id, (string) ($payload['content'] ?? ''), (string) ($payload['cover'] ?? ''));

            return $this->success(['id' => $id, 'saved_at' => date('H:i:s')], '已自动保存');
        }

        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $old = $row->toArray();

        // 标题不允许被自动保存清空
        if (trim((string) ($data['title'] ?? '')) === '') {
            $data['title'] = $old['title'];
        }
        unset($data['status'], $data['is_top']);

        $tagNames = $this->tagNames($data);
        $payload  = $this->buildPayload($data, $old);
        unset($payload['status'], $payload['is_top']);
        $payload['update_time'] = date('Y-m-d H:i:s');
        $row->save($payload);

        $current = array_merge($old, $payload);
        // 与 update() 同口径：只有显式传了 tags 才同步，避免自动保存顺带清空标签
        if (array_key_exists('tags', $data)) {
            TagService::syncForContent($id, $tagNames);
        }
        AttachmentService::syncForContent($id, (string) ($current['content'] ?? ''), (string) ($current['cover'] ?? ''));

        return $this->success(['id' => $id, 'saved_at' => date('H:i:s')], '已自动保存');
    }

    /**
     * 回收站列表（M2.3）
     */
    public function trashed()
    {
        $this->checkPermission('content.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = Db::name('content')
            ->alias('c')
            ->field('c.*, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNotNull('c.delete_time');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('c.title|c.author|c.summary', 'like', '%' . $keyword . '%');
        }

        $total = (int) (clone $query)->count();

        $list = $query
            ->order('c.delete_time', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $statusMap = Content::statusMap();
        foreach ($list as &$item) {
            $item['status_text']   = $statusMap[(int) $item['status']] ?? '未知';
            $item['category_name'] = $item['category_name'] ?? '未分类';
            $item['tags']          = [];
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
     * 回收站内容详情（只读预览，用于确认是否恢复/彻底删除）
     */
    public function trashedRead($id = 0)
    {
        $this->checkPermission('content.list');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('content')->where('id', $id)->whereNotNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或不在回收站中', 404);
        }

        $row['category_name'] = (string) Db::name('category')->where('id', (int) $row['category_id'])->value('name');
        $row['tags']          = TagService::namesOf($id);
        $row['status_text']   = Content::statusMap()[(int) $row['status']] ?? '未知';

        return $this->success($row);
    }

    /**
     * 从回收站恢复
     */
    public function restore($id = 0)
    {
        $this->checkPermission('content.restore');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('content')->where('id', $id)->whereNotNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不在回收站中', 404);
        }

        Db::name('content')->where('id', $id)->update([
            'delete_time' => null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        // 恢复后重建附件引用（彻底删除时已清理过）
        AttachmentService::syncForContent($id, (string) $row['content'], (string) $row['cover']);

        $this->log('content', 'restore', '恢复内容：' . $row['title']);

        return $this->success(null, '已恢复');
    }

    /**
     * 彻底删除（不可恢复）
     */
    public function forceDelete($id = 0)
    {
        $this->checkPermission('content.force-delete');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('content')->where('id', $id)->whereNotNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不在回收站中', 404);
        }

        $this->destroyRelations($id);
        Db::name('content')->where('id', $id)->delete();

        $this->log('content', 'force-delete', '彻底删除内容：' . $row['title']);

        return $this->success(null, '已彻底删除');
    }

    /**
     * 批量恢复
     */
    public function batchRestore()
    {
        $this->checkPermission('content.restore');

        $ids = $this->idList();
        if (!$ids) {
            return $this->fail('请先选择要恢复的内容');
        }

        $rows = Db::name('content')
            ->whereIn('id', $ids)
            ->whereNotNull('delete_time')
            ->select()
            ->toArray();

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            Db::name('content')->where('id', (int) $row['id'])->update([
                'delete_time' => null,
                'update_time' => $now,
            ]);
            AttachmentService::syncForContent((int) $row['id'], (string) $row['content'], (string) $row['cover']);
        }

        $this->log('content', 'batch-restore', '批量恢复内容 ' . count($rows) . ' 条');

        return $this->success(['count' => count($rows)], '已恢复 ' . count($rows) . ' 条内容');
    }

    /**
     * 批量彻底删除
     */
    public function batchForceDelete()
    {
        $this->checkPermission('content.force-delete');

        $ids = $this->idList();
        if (!$ids) {
            return $this->fail('请先选择要彻底删除的内容');
        }

        $rows = Db::name('content')
            ->whereIn('id', $ids)
            ->whereNotNull('delete_time')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $this->destroyRelations((int) $row['id']);
            Db::name('content')->where('id', (int) $row['id'])->delete();
        }

        $this->log('content', 'batch-force-delete', '批量彻底删除内容 ' . count($rows) . ' 条');

        return $this->success(['count' => count($rows)], '已彻底删除 ' . count($rows) . ' 条内容');
    }

    /**
     * 复制内容（M2.5）：副本一律存为草稿
     */
    public function copy($id = 0)
    {
        $this->checkPermission('content.copy');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Content::where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('内容不存在或已删除', 404);
        }

        $source = $row->toArray();
        $data   = $this->params();

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = mb_substr((string) $source['title'], 0, 180) . ' 副本';
        }

        $payload = [
            'category_id'  => (int) ($data['category_id'] ?? $source['category_id']),
            'title'        => $title,
            'author'       => $source['author'],
            'cover'        => $source['cover'],
            'tags'         => (string) $source['tags'],
            'summary'      => $source['summary'],
            'content'      => $source['content'],
            'status'       => Content::STATUS_DRAFT,
            'is_top'       => 0,
            'views'        => 0,
            'sort'         => 0,
            'publish_time' => null,
            'create_time'  => date('Y-m-d H:i:s'),
            'update_time'  => date('Y-m-d H:i:s'),
        ];

        $model = new Content();
        $model->save($payload);
        $newId = (int) $model->getAttr('id');

        TagService::syncForContent($newId, TagService::textToNames((string) $source['tags']));
        AttachmentService::syncForContent($newId, (string) $payload['content'], (string) $payload['cover']);

        $this->log('content', 'copy', '复制内容：' . $title);

        return $this->success(['id' => $newId], '已复制为草稿');
    }

    /**
     * 导出（M2.6）：CSV 或 Markdown 打包 ZIP
     *
     * 走 JSON 返回（ZIP 用 base64），前端自行组装 Blob 下载，
     * 避免附件下载请求带不上鉴权头。
     */
    public function export()
    {
        $this->checkPermission('content.export');

        $data   = $this->params();
        $ids    = $this->idList($data);
        $format = strtolower(trim((string) ($data['format'] ?? 'csv')));

        $query = Db::name('content')
            ->alias('c')
            ->field('c.*, cat.name as category_name')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->whereNull('c.delete_time');

        if ($ids) {
            $query->whereIn('c.id', $ids);
        }

        $list = $query->order('c.id', 'desc')->limit(2000)->select()->toArray();
        if (!$list) {
            return $this->fail('没有可导出的内容');
        }

        $tagMap = TagService::namesForContents(array_column($list, 'id'));
        foreach ($list as &$item) {
            $item['tags'] = $tagMap[(int) $item['id']] ?? [];
        }
        unset($item);

        $stamp = date('Ymd_His');
        $this->log('content', 'export', '导出内容 ' . count($list) . ' 篇');

        if (in_array($format, ['zip', 'md', 'markdown'], true)) {
            if (!class_exists('ZipArchive')) {
                return $this->fail('当前环境未启用 ZIP 扩展，无法导出 Markdown 包');
            }

            $dir = app()->getRuntimePath() . 'temp';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return $this->fail('临时目录创建失败');
            }

            $zipPath = $dir . DIRECTORY_SEPARATOR . 'export_' . $stamp . '.zip';
            $zip     = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return $this->fail('文件打包失败');
            }

            foreach ($list as $content) {
                $zip->addFromString(self::safeFileName((string) $content['title']) . '.md', ContentImportExport::buildMarkdown($content));
            }
            $zip->close();

            $binary = (string) file_get_contents($zipPath);
            @unlink($zipPath);

            return $this->success([
                'filename' => 'contents_' . $stamp . '.zip',
                'mime'     => 'application/zip',
                'encoding' => 'base64',
                'content'  => base64_encode($binary),
                'count'    => count($list),
            ], '导出成功');
        }

        return $this->success([
            'filename' => 'contents_' . $stamp . '.csv',
            'mime'     => 'text/csv;charset=utf-8',
            'encoding' => 'utf8',
            'content'  => ContentImportExport::buildCsv($list),
            'count'    => count($list),
        ], '导出成功');
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
     *
     * @param array      $data 提交数据
     * @param array|null $old  更新前的记录；用于判断「发布时间原本是否已有」。
     *                         传 null 表示新建。
     */
    protected function buildPayload(array $data, ?array $old = null): array
    {
        $payload = [];
        foreach ($this->editableFields() as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['category_id', 'status', 'is_top', 'views', 'sort'], true)) {
                $value = (int) $value;
            } elseif ($field === 'tags') {
                // 表单提交的是数组，落库为逗号串；标签关联表才是权威来源
                $value = is_array($value) ? TagService::namesToText($value) : trim((string) $value);
            } elseif ($field === 'content') {
                // 正文一律经白名单净化：编辑器保存与导入共用这一个入口
                $value = HtmlFilter::clean(is_string($value) ? $value : '');
            } else {
                $value = is_string($value) ? trim($value) : $value;
            }
            $payload[$field] = $value;
        }

        // 发布时间：只有调用方显式传了才处理。
        // 不传时保持原值不动 —— 否则只改标签、只改状态这类局部更新，
        // 会把已有的发布时间静默重置为当前时间或清空。
        if (array_key_exists('publish_time', $data)) {
            $payload['publish_time'] = empty($data['publish_time'])
                ? null
                : date('Y-m-d H:i:s', strtotime((string) $data['publish_time']));
        }

        // 发布状态却没有发布时间时补当前时间：
        // 更新场景要尊重原有值（上面的分支已处理显式传值），
        // 只有「原本就没有」才填充，避免覆盖既有发布时间
        if (
            ($payload['status'] ?? null) === Content::STATUS_PUBLISHED
            && empty($payload['publish_time'])
            && empty($old['publish_time'])
        ) {
            $payload['publish_time'] = date('Y-m-d H:i:s');
        }

        return $payload;
    }

    /**
     * 从提交数据中取标签名数组
     */
    private function tagNames(array $data): array
    {
        $tags = $data['tags'] ?? [];
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        return TagService::normalize((array) $tags);
    }

    /**
     * 取提交的 ID 列表
     */
    private function idList(?array $data = null): array
    {
        $data = $data ?? $this->params();
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = explode(',', (string) $ids);
        }

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * 彻底删除内容时的关联清理
     */
    private function destroyRelations(int $contentId): void
    {
        TagService::clearForContent($contentId);
        AttachmentService::clearForContent($contentId);
    }

    /**
     * 文件名安全化
     */
    private static function safeFileName(string $name): string
    {
        $name = (string) preg_replace('/[\\/:*?"<>|]+/', '', $name);
        $name = trim($name);

        return mb_substr($name !== '' ? $name : 'untitled', 0, 60);
    }
}
