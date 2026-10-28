<?php
declare (strict_types = 1);

namespace app\model;

use app\service\Permission;
use think\Model;

/**
 * 管理员模型
 */
class Admin extends Model
{
    /** 角色 */
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_EDITOR = 'editor';

    /** 状态 */
    public const STATUS_ENABLE  = 1;
    public const STATUS_DISABLE = 0;

    protected $name = 'admin';

    protected $hidden = ['password', 'delete_time'];

    protected $append = ['role_text', 'status_text'];

    /**
     * 密码自动加密
     */
    public function setPasswordAttr($value)
    {
        $value = (string) $value;
        // 已是 bcrypt 散列则不再重复加密
        if (preg_match('/^\$2[ayb]\$/', $value)) {
            return $value;
        }
        return password_hash($value, PASSWORD_BCRYPT);
    }

    public function getRoleTextAttr($value, array $data): string
    {
        return ($data['role'] ?? '') === self::ROLE_ADMIN ? '超级管理员' : '编辑';
    }

    public function getStatusTextAttr($value, array $data): string
    {
        return (int) ($data['status'] ?? 0) === self::STATUS_ENABLE ? '启用' : '禁用';
    }

    /**
     * 读取权限：数据库里的 JSON 字符串自动转成数组
     */
    public function getPermissionsAttr($value): array
    {
        return Permission::decode($value);
    }

    /**
     * 写入权限：数组自动转成 JSON 字符串，并过滤掉非法 key
     */
    public function setPermissionsAttr($value): string
    {
        return json_encode(Permission::normalize(is_array($value) ? $value : []), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 角色列表
     */
    public static function roleOptions(): array
    {
        return [
            ['label' => '超级管理员', 'value' => self::ROLE_ADMIN],
            ['label' => '编辑', 'value' => self::ROLE_EDITOR],
        ];
    }
}
