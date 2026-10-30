<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 附件素材模型
 */
class Attachment extends Model
{
    /** 类型：图片 / 视频 / 附件 */
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_FILE  = 'file';

    protected $name = 'attachment';

    protected $hidden = ['delete_time'];

    protected $append = ['type_text', 'size_text'];

    public function getTypeTextAttr($value, array $data): string
    {
        return self::typeMap()[(string) ($data['type'] ?? '')] ?? '未知';
    }

    public function getSizeTextAttr($value, array $data): string
    {
        $size = (int) ($data['size'] ?? 0);
        if ($size >= 1024 * 1024) {
            return round($size / 1024 / 1024, 1) . ' MB';
        }
        if ($size >= 1024) {
            return round($size / 1024, 1) . ' KB';
        }

        return $size . ' B';
    }

    public static function typeMap(): array
    {
        return [
            self::TYPE_IMAGE => '图片',
            self::TYPE_VIDEO => '视频',
            self::TYPE_FILE  => '附件',
        ];
    }

    public static function typeOptions(): array
    {
        $options = [];
        foreach (self::typeMap() as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }

        return $options;
    }
}
