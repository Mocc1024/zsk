<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 内容模型
 */
class Content extends Model
{
    /** 状态：草稿 / 已发布 / 已下架 */
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;
    public const STATUS_OFFLINE   = 2;

    protected $name = 'content';

    protected $hidden = ['delete_time'];

    protected $append = ['status_text'];

    public function getStatusTextAttr($value, array $data): string
    {
        return self::statusMap()[(int) ($data['status'] ?? 0)] ?? '未知';
    }

    public static function statusMap(): array
    {
        return [
            self::STATUS_DRAFT     => '草稿',
            self::STATUS_PUBLISHED => '已发布',
            self::STATUS_OFFLINE   => '已下架',
        ];
    }

    public static function statusOptions(): array
    {
        $options = [];
        foreach (self::statusMap() as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }
        return $options;
    }
}
