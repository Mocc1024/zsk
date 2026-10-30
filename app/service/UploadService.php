<?php
declare (strict_types = 1);

namespace app\service;

use app\model\Attachment;
use app\service\AttachmentFolderService;
use think\exception\ValidateException;
use think\file\UploadedFile;

/**
 * 上传服务：校验 → 存盘 → 入库
 *
 * 安全要点：
 *   · 扩展名与 MIME 双重白名单（MIME 由服务端 finfo 探测，不信任客户端）
 *   · 图片额外用 getimagesize 二次校验，伪装图片直接拒绝
 *   · 文件名随机生成，杜绝 .php 等可执行后缀与路径穿越
 *   · 落盘目录为 public/storage，该目录已配置为禁止解析脚本
 */
class UploadService
{
    /** 图片类型允许的附加 MIME（finfo 与 getimagesize 结果可能不同） */
    private const IMAGE_MIME_ALIAS = ['image/jpg' => 'image/jpeg'];

    /**
     * 保存上传文件并写入附件表
     *
     * @param UploadedFile $file   上传文件对象
     * @param string       $type   image / video / file
     * @param string       $folder 分组标识
     * @return array 附件记录（含 id）
     * @throws ValidateException 校验失败
     */
    public static function store(UploadedFile $file, string $type, string $folder = ''): array
    {
        $typeConfig = self::config($type);

        if (!$file->isValid()) {
            throw new ValidateException('文件上传失败，请重试');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new ValidateException('文件内容为空');
        }
        if ($size > $typeConfig['max_size']) {
            throw new ValidateException('文件大小超出限制，最大 ' . self::formatSize($typeConfig['max_size']));
        }

        $ext = strtolower((string) $file->getOriginalExtension());
        if ($ext === '' || !in_array($ext, $typeConfig['ext'], true)) {
            throw new ValidateException('不允许的文件格式：' . ($ext === '' ? '未知' : $ext));
        }
        if ($ext === 'svg') {
            throw new ValidateException('SVG 可内嵌脚本，已禁止上传');
        }

        $mime = self::detectMime($file->getPathname());
        $mime = self::IMAGE_MIME_ALIAS[$mime] ?? $mime;
        if ($mime === '' || !in_array($mime, $typeConfig['mime'], true)) {
            throw new ValidateException('文件内容与扩展名不符，已拒绝：' . $ext);
        }

        $width  = 0;
        $height = 0;
        if ($type === 'image') {
            $info = @getimagesize($file->getPathname());
            if ($info === false || empty($info[0])) {
                throw new ValidateException('文件内容不是有效图片');
            }
            $width  = (int) $info[0];
            $height = (int) $info[1];
        }

        [$subPath, $fileName] = self::targetPath($type, $ext);
        $absoluteDir = self::storageRoot() . '/' . $subPath;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new ValidateException('上传目录创建失败，请检查目录权限');
        }

        $file->move($absoluteDir, $fileName);

        $url = '/storage/' . $subPath . '/' . $fileName;
        $now = date('Y-m-d H:i:s');

        // 分组不存在时自动登记，保证「素材库分组」里总能看到上传目标分组
        $folderName = AttachmentFolderService::ensure($folder);

        $data = [
            'type'        => $type,
            'folder'      => $folderName,
            'name'        => self::safeOriginalName($file->getOriginalName()),
            'path'        => $subPath . '/' . $fileName,
            'url'         => $url,
            'mime'        => $mime,
            'ext'         => $ext,
            'size'        => $size,
            'width'       => $width,
            'height'      => $height,
            'ref_count'   => 0,
            'admin_id'    => (int) (Context::admin()['id'] ?? 0),
            'create_time' => $now,
            'update_time' => $now,
        ];

        $model = new Attachment();
        $model->save($data);

        $data['id'] = (int) $model->getAttr('id');

        return $data;
    }

    /**
     * 读取指定类型的上传配置
     */
    private static function config(string $type): array
    {
        $types = (array) config('upload.types', []);
        if (!isset($types[$type])) {
            throw new ValidateException('不支持的文件类型：' . $type);
        }

        return $types[$type];
    }

    /**
     * 物理存储根目录：public/storage
     */
    private static function storageRoot(): string
    {
        return rtrim(app()->getRootPath(), '\\/') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'storage';
    }

    /**
     * 生成目标路径：{type}/{Ymd} 与随机文件名
     */
    private static function targetPath(string $type, string $ext): array
    {
        $dateFormat = (string) config('upload.date_format', 'Ymd');
        $dirs       = (array) config('upload.dir', []);

        $subPath = ($dirs[$type] ?? $type) . '/' . date($dateFormat);
        $name    = date('His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

        return [$subPath, $name];
    }

    /**
     * 服务端探测真实 MIME
     */
    private static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $mime = (string) finfo_file($finfo, $path);
        finfo_close($finfo);

        return strtolower(trim($mime));
    }

    /**
     * 原始文件名仅用于展示，去掉路径部分并限制长度
     */
    private static function safeOriginalName(string $name): string
    {
        $name = str_replace(['\\', '/'], '', $name);

        return mb_substr($name, 0, 200);
    }

    /**
     * 字节数转可读文本
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024) . 'MB';
        }

        return max(1, (int) round($bytes / 1024)) . 'KB';
    }
}
