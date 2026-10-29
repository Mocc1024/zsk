<?php
declare (strict_types = 1);

namespace app\model;

use think\facade\Db;
use think\Model;

/**
 * 管理员模型
 *
 * 权限不再存于本表，统一由所属用户组（zsk_auth_group）决定。
 */
class Admin extends Model
{
    /** 状态 */
    public const STATUS_ENABLE  = 1;
    public const STATUS_DISABLE = 0;

    protected $name = 'admin';

    protected $hidden = ['password', 'delete_time'];

    protected $append = ['status_text', 'group_title', 'group_names'];

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
        return (int) ($data['status'] ?? 0) === self::STATUS_ENABLE ? '启用' : '禁用';
    }

    /**
     * 主用户组名称
     */
    public function getGroupTitleAttr($value, array $data): string
    {
        $gid = (int) ($data['group_id'] ?? 0);
        if ($gid <= 0) {
            return '未分配';
        }
        return (string) (Db::name('auth_group')->where('id', $gid)->value('title') ?: '未分配');
    }

    /**
     * 所属的全部用户组（主组 + 附加组）
     */
    public function getGroupNamesAttr($value, array $data): array
    {
        $gid = (int) ($data['group_id'] ?? 0);
        $ids = Db::name('auth_group_access')
            ->where('uid', (int) ($data['id'] ?? 0))
            ->column('group_id');

        $ids   = array_values(array_unique(array_filter(array_merge([$gid], array_map('intval', $ids)))));
        if (!$ids) {
            return [];
        }

        return Db::name('auth_group')->whereIn('id', $ids)->column('title');
    }
}
