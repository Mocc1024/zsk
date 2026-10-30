<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\AttachmentFolder;
use app\service\AttachmentFolderService;
use think\facade\Db;

/**
 * 素材分组：列表 / 新增 / 重命名 / 删除
 */
class AttachmentFolderController extends BaseController
{
    /**
     * 分组列表（含素材数量）
     */
    public function index()
    {
        $this->checkPermission('attachment.list');

        return $this->success([
            'list'  => AttachmentFolderService::all(),
            'total' => AttachmentFolderService::totalCount(),
        ]);
    }

    /**
     * 新增分组
     */
    public function save()
    {
        $this->checkPermission('attachment.folder');

        $data = $this->params();
        $name = AttachmentFolderService::normalize((string) ($data['name'] ?? ''));
        $sort = (int) ($data['sort'] ?? 0);

        if ($name === '') {
            return $this->fail('请输入分组名称');
        }
        if (mb_strlen($name) > AttachmentFolder::MAX_LENGTH) {
            return $this->fail('分组名称不能超过 ' . AttachmentFolder::MAX_LENGTH . ' 个字符');
        }
        if (AttachmentFolderService::exists($name)) {
            return $this->fail('分组「' . $name . '」已存在');
        }

        $now = date('Y-m-d H:i:s');
        $id  = Db::name('attachment_folder')->insertGetId([
            'name'        => $name,
            'sort'        => $sort,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $this->log('attachment', 'folder-create', '新增素材分组：' . $name);

        return $this->success(['id' => (int) $id, 'name' => $name], '分组已创建');
    }

    /**
     * 重命名 / 改排序
     */
    public function update($id = 0)
    {
        $this->checkPermission('attachment.folder');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = Db::name('attachment_folder')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('分组不存在', 404);
        }

        if (array_key_exists('sort', $data)) {
            Db::name('attachment_folder')->where('id', $id)->update([
                'sort'        => (int) $data['sort'],
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        $newName = array_key_exists('name', $data)
            ? AttachmentFolderService::normalize((string) $data['name'])
            : '';

        if ($newName === '') {
            return $this->success(null, '保存成功');
        }
        if (mb_strlen($newName) > AttachmentFolder::MAX_LENGTH) {
            return $this->fail('分组名称不能超过 ' . AttachmentFolder::MAX_LENGTH . ' 个字符');
        }
        if (AttachmentFolderService::exists($newName, $id)) {
            return $this->fail('分组「' . $newName . '」已存在');
        }

        $result = AttachmentFolderService::rename($id, $newName);
        $this->log('attachment', 'folder-rename', '重命名素材分组：' . $result['old'] . ' → ' . $result['new']);

        return $this->success($result, '分组已更新');
    }

    /**
     * 删除分组：其下素材自动移入 default
     */
    public function delete($id = 0)
    {
        $this->checkPermission('attachment.folder');

        $id = (int) ($id ?: $this->request->param('id'));

        $row = Db::name('attachment_folder')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('分组不存在', 404);
        }
        if ((string) $row['name'] === AttachmentFolder::DEFAULT_NAME) {
            return $this->fail('「' . AttachmentFolder::DEFAULT_NAME . '」是系统默认分组，不能删除');
        }

        $result = AttachmentFolderService::remove($id);
        $this->log('attachment', 'folder-delete', '删除素材分组：' . $result['name']);

        return $this->success(
            $result,
            $result['moved'] > 0
                ? '分组已删除，' . $result['moved'] . ' 个素材已移入 ' . AttachmentFolder::DEFAULT_NAME
                : '分组已删除'
        );
    }
}
