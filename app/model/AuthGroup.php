<?php
declare (strict_types = 1);

namespace app\model;

use think\facade\Db;
use think\Model;

/**
 * 用户组（角色）模型
 */
class AuthGroup extends Model
{
    protected $name = 'auth_group';

    protected $append = ['status_text', 'rule_ids', 'member_count'];

    public function getStatusTextAttr($value, array $data): string
    {
        return (int) ($data['status'] ?? 0) === 1 ? '启用' : '禁用';
    }

    /**
     * rules（逗号分隔）转成 ID 数组
     */
    public function getRuleIdsAttr($value, array $data): array
    {
        $raw = (string) ($data['rules'] ?? '');
        if ($raw === '') {
            return [];
        }
        $ids = array_map('intval', explode(',', $raw));
        return array_values(array_filter($ids, static fn ($v) => $v > 0));
    }

    /**
     * 组内成员数量
     */
    public function getMemberCountAttr($value, array $data): int
    {
        return (int) Db::name('auth_group_access')->where('group_id', (int) ($data['id'] ?? 0))->count();
    }
}
