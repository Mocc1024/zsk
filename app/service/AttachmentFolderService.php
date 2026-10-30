<?php
declare (strict_types = 1);

namespace app\service;

use app\model\AttachmentFolder;
use think\facade\Db;

/**
 * 素材分组服务
 *
 * 分组是素材库的一等公民（zsk_attachment_folder），
 * zsk_attachment.folder 保存分组名，两者通过名称关联：
 *   · 上传 / 编辑素材时 ensure() 自动登记不存在的分组
 *   · 重命名分组会同步改写其下所有素材的 folder
 *   · 删除分组时，其下素材统一移入 default，绝不误删素材
 */
class AttachmentFolderService
{
    /**
     * 规范化分组名：去空白、压缩内部空格、限长、空值回退默认分组
     */
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', trim($name)));
        if ($name === '') {
            return AttachmentFolder::DEFAULT_NAME;
        }

        return mb_substr($name, 0, AttachmentFolder::MAX_LENGTH);
    }

    /**
     * 分组列表（含每个分组的素材数量）
     */
    public static function all(): array
    {
        $folders = Db::name('attachment_folder')
            ->whereNull('delete_time')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $counts = Db::name('attachment')
            ->whereNull('delete_time')
            ->group('folder')
            ->column('COUNT(*)', 'folder');

        foreach ($folders as &$folder) {
            $folder['count'] = (int) ($counts[$folder['name']] ?? 0);
        }
        unset($folder);

        return $folders;
    }

    /**
     * 素材总数（用于「全部」分组）
     */
    public static function totalCount(): int
    {
        return (int) Db::name('attachment')->whereNull('delete_time')->count();
    }

    /**
     * 保证分组存在：不存在则登记，返回规范化后的分组名
     */
    public static function ensure(string $name): string
    {
        $name = self::normalize($name);

        $exists = Db::name('attachment_folder')
            ->where('name', $name)
            ->count();

        if (!$exists) {
            $now = date('Y-m-d H:i:s');
            Db::name('attachment_folder')->insert([
                'name'        => $name,
                'sort'        => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        return $name;
    }

    /**
     * 分组是否存在（含同名校验用）
     */
    public static function exists(string $name, int $exceptId = 0): bool
    {
        $query = Db::name('attachment_folder')
            ->where('name', self::normalize($name))
            ->whereNull('delete_time');

        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }

        return (int) $query->count() > 0;
    }

    /**
     * 重命名分组，并同步改写其下素材
     *
     * @return array{old:string,new:string,moved:int}
     */
    public static function rename(int $id, string $name): array
    {
        $name = self::normalize($name);
        $old  = (string) Db::name('attachment_folder')->where('id', $id)->value('name');

        $moved = 0;
        if ($old !== '' && $old !== $name) {
            $moved = Db::name('attachment')
                ->where('folder', $old)
                ->update(['folder' => $name, 'update_time' => date('Y-m-d H:i:s')]);
        }

        Db::name('attachment_folder')->where('id', $id)->update([
            'name'        => $name,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        // 若同名分组已存在（例如历史脏数据），合并后删除多余记录交由调用方处理
        return ['old' => $old, 'new' => $name, 'moved' => (int) $moved];
    }

    /**
     * 删除分组：其下素材统一移入 default，分组本身软删除
     *
     * @return array{name:string,moved:int}
     */
    public static function remove(int $id): array
    {
        $name = (string) Db::name('attachment_folder')
            ->where('id', $id)
            ->whereNull('delete_time')
            ->value('name');

        $moved = 0;
        if ($name !== '' && $name !== AttachmentFolder::DEFAULT_NAME) {
            $moved = Db::name('attachment')
                ->where('folder', $name)
                ->update([
                    'folder'      => AttachmentFolder::DEFAULT_NAME,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        }

        Db::name('attachment_folder')->where('id', $id)->update([
            'delete_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        return ['name' => $name, 'moved' => (int) $moved];
    }
}
