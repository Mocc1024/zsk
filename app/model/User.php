<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * C 端用户模型
 *
 * 与 zsk_admin（后台管理员）完全隔离：不同表、不同令牌角色、不同中间件。
 */
class User extends Model
{
    /** 状态 */
    public const STATUS_NORMAL  = 1;
    public const STATUS_DISABLE = 0;

    protected $name = 'user';

    protected $hidden = ['password', 'delete_time'];

    protected $append = ['status_text'];

    /**
     * 密码强度：至少 8 位，且同时含字母与数字
     *
     * 放在模型里而不是控制器里，是为了让「前台注册」与「后台新建用户」
     * 共用同一套规则——两处各写一份迟早会不一致。
     */
    public static function strongEnough(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[a-zA-Z]/', $password)
            && preg_match('/\d/', $password);
    }

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

    public function getStatusTextAttr($value, array $data): string
    {
        return (int) ($data['status'] ?? 0) === self::STATUS_NORMAL ? '正常' : '已禁用';
    }
}
