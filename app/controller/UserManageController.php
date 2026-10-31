<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\service\PointService;
use think\facade\Db;

/**
 * 后台：C 端用户管理
 *
 * 与后台管理员（AdminController）是两套人：这里管的是 zsk_user，
 * 改的是 C 端用户的资料与账目，不能和后台账号的操作混在一起。
 *
 * 调积分/经验一律走 PointService，不直接改 zsk_user.points ——
 * 直接改余额不记流水，用户申诉时没有任何凭据。
 */
class UserManageController extends BaseController
{
    /**
     * 列表（分页 + 关键字 + 状态）
     */
    public function index()
    {
        $this->checkPermission('user.list');

        [$page, $limit] = $this->pageInfo();

        $query = Db::name('user')->alias('u')->field('u.*, l.name as level_name')->join('user_level l', 'u.level = l.level', 'LEFT');

        $keyword = trim((string) $this->input('keyword', ''));
        if ($keyword !== '') {
            $query->where('u.username|u.nickname|u.mobile', 'like', '%' . $keyword . '%');
        }
        $status = $this->input('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('u.status', (int) $status);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('u.id', 'desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            unset($item['password']);
            $item['status_text']   = (int) $item['status'] === User::STATUS_NORMAL ? '正常' : '已禁用';
            $item['deleted']       = !empty($item['delete_time']);
            $item['level_name']    = $item['level_name'] ?: 'Lv.' . $item['level'];
        }
        unset($item);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 新建用户
     *
     * 不传 password 时自动生成随机初始密码并回传——
     * 管理员要把初始密码告知用户，不返回就没人知道是什么。
     */
    public function save()
    {
        $this->checkPermission('user.create');

        $data     = $this->params();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
            return $this->fail('用户名需为 4-20 位字母、数字或下划线');
        }

        $generated = false;
        if ($password === '') {
            $password  = self::randomPassword();
            $generated = true;
        } elseif (!User::strongEnough($password)) {
            return $this->fail('密码至少 8 位，且需同时包含字母和数字');
        }

        // 判重要连同已注销（冷却期内）的一起查：唯一索引覆盖软删记录，
        // 只查未删除的话会撞库报 500，而不是给出友好提示
        if (User::where('username', $username)->find()) {
            return $this->fail('该用户名已被占用');
        }

        $mobile = trim((string) ($data['mobile'] ?? ''));
        if ($mobile !== '' && User::where('mobile', $mobile)->find()) {
            return $this->fail('该手机号已被占用');
        }

        $user = new User();
        $user->username = $username;
        $user->password = $password;
        $user->nickname = trim((string) ($data['nickname'] ?? '')) ?: $username;
        $user->email    = trim((string) ($data['email'] ?? ''));
        // 不填手机号必须写 NULL：唯一索引允许多个 NULL，但不允许两个空串
        $user->mobile   = $mobile !== '' ? $mobile : null;
        $user->status   = (int) ($data['status'] ?? User::STATUS_NORMAL) === User::STATUS_NORMAL
            ? User::STATUS_NORMAL
            : User::STATUS_DISABLE;
        $user->level    = 1;
        $user->save();

        $this->log('user', 'create', '新增用户 #' . $user->getAttr('id') . '：' . $username);

        return $this->success([
            'id'       => (int) $user->getAttr('id'),
            'password' => $generated ? $password : '',
        ], $generated ? '用户已创建，初始密码为 ' . $password : '用户已创建');
    }

    /**
     * 详情
     */
    public function read($id = 0)
    {
        $this->checkPermission('user.view');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('user')->where('id', $id)->find();
        if (!$row) {
            return $this->fail('用户不存在', 404);
        }
        $row = is_array($row) ? $row : $row->toArray();
        unset($row['password']);

        return $this->success(['user' => $row]);
    }

    /**
     * 编辑资料
     */
    public function update($id = 0)
    {
        $this->checkPermission('user.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $update = ['update_time' => date('Y-m-d H:i:s')];
        foreach (['nickname', 'email', 'mobile', 'bio'] as $field) {
            if (isset($data[$field])) {
                $update[$field] = trim((string) $data[$field]);
            }
        }

        Db::name('user')->where('id', $id)->update($update);
        $this->log('user', 'update', '编辑用户 #' . $id);

        return $this->success(null, '已保存');
    }

    /**
     * 启停
     */
    public function status($id = 0)
    {
        $this->checkPermission('user.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $status = (int) $this->input('status', 0) === User::STATUS_NORMAL ? User::STATUS_NORMAL : User::STATUS_DISABLE;

        Db::name('user')->where('id', $id)->update([
            'status'      => $status,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('user', 'status', ($status === User::STATUS_NORMAL ? '启用' : '禁用') . '用户 #' . $id);

        return $this->success(null, $status === User::STATUS_NORMAL ? '已启用' : '已禁用');
    }

    /**
     * 重置密码
     *
     * 不传 password 时自动生成随机密码并返回给管理员——
     * 管理员需要把新密码告知用户，不返回就没人知道是什么。
     */
    public function resetPassword($id = 0)
    {
        $this->checkPermission('user.reset-password');

        $id = (int) ($id ?: $this->request->param('id'));

        $given = trim((string) $this->input('password', ''));
        if ($given !== '' && strlen($given) < 8) {
            return $this->fail('密码至少 8 位');
        }
        $newPassword = $given !== '' ? $given : self::randomPassword();

        Db::name('user')->where('id', $id)->update([
            'password'    => password_hash($newPassword, PASSWORD_BCRYPT),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('user', 'reset-password', '重置用户密码 #' . $id);

        return $this->success(['password' => $newPassword], '密码已重置');
    }

    /**
     * 调整积分
     */
    public function points($id = 0)
    {
        $this->checkPermission('user.points');

        $id     = (int) ($id ?: $this->request->param('id'));
        $points = (int) $this->input('points', 0);
        $remark = trim((string) $this->input('remark', ''));

        if ($points === 0) {
            return $this->fail('请输入积分变动值');
        }

        $ok = PointService::change($id, $points, 'admin_adjust', $remark ?: '管理员调整');
        if (!$ok) {
            return $this->fail('调整失败，可能是用户不存在或积分不足');
        }

        $this->log('user', 'points', '调整用户积分 #' . $id . '：' . ($points > 0 ? '+' : '') . $points);

        return $this->success(null, '积分已调整');
    }

    /**
     * 调整经验
     */
    public function exp($id = 0)
    {
        $this->checkPermission('user.exp');

        $id  = (int) ($id ?: $this->request->param('id'));
        $exp = (int) $this->input('exp', 0);

        if ($exp === 0) {
            return $this->fail('请输入经验变动值');
        }

        // PointService 只支持增加，后台要能减经验就先走扣分场景的记账
        if ($exp < 0) {
            return $this->fail('经验暂不支持减少，如需修正请直接调整等级');
        }

        $level = PointService::addExp($id, $exp, 'admin_adjust');
        $this->log('user', 'exp', '调整用户经验 #' . $id . '：+' . $exp);

        return $this->success(['level' => $level], '经验已调整，当前等级 Lv.' . $level);
    }

    /**
     * 恢复注销账号（冷却期内）
     */
    public function restore($id = 0)
    {
        $this->checkPermission('user.update');

        $id = (int) ($id ?: $this->request->param('id'));
        Db::name('user')->where('id', $id)->update([
            'delete_time' => null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('user', 'restore', '恢复注销用户 #' . $id);

        return $this->success(null, '账号已恢复');
    }

    private static function randomPassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $pwd   = '';
        for ($i = 0; $i < 12; $i++) {
            $pwd .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pwd;
    }
}
