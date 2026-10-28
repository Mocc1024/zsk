<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 内容分类模型
 */
class Category extends Model
{
    public const STATUS_ENABLE  = 1;
    public const STATUS_DISABLE = 0;

    protected $name = 'category';

    protected $hidden = ['delete_time'];

    protected $append = ['status_text'];

    public function getStatusTextAttr($value, array $data): string
    {
        return (int) ($data['status'] ?? 0) === self::STATUS_ENABLE ? '启用' : '禁用';
    }

    /**
     * 分类下的内容数量
     */
    public function contentCount(): int
    {
        return (int) Content::where('category_id', $this->getAttr('id'))
            ->whereNull('delete_time')
            ->count();
    }
}
