<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 权限菜单模型
 */
class AuthRule extends Model
{
    /** 类型：菜单 */
    public const TYPE_MENU = 1;
    /** 类型：权限点（按钮 / 接口） */
    public const TYPE_ACTION = 2;

    protected $name = 'auth_rule';

    protected $append = ['type_text', 'status_text'];

    public function getTypeTextAttr($value, array $data): string
    {
        return (int) ($data['type'] ?? self::TYPE_MENU) === self::TYPE_MENU ? '菜单' : '权限点';
    }

    public function getStatusTextAttr($value, array $data): string
    {
        return (int) ($data['status'] ?? 0) === 1 ? '启用' : '禁用';
    }
}
