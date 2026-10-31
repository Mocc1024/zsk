<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Admin;
use app\service\Context;
use app\service\Jwt;
use app\service\Permission;

/**
 * 登录认证
 */
class AuthController extends BaseController
{
    /**
     * 登录
     */
    public function login()
    {
        $data     = $this->params();
        $username = trim((string) ($data['username'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));

        if ($username === '' || $password === '') {
            return $this->fail('请输入账号和密码');
        }

        // 已删除的账号不允许登录
        $admin = Admin::where('username', $username)->whereNull('delete_time')->find();
        if (!$admin || !password_verify($password, $admin->getAttr('password'))) {
            return $this->fail('账号或密码错误', 1001, null, 200);
        }
        if ((int) $admin->getAttr('status') !== Admin::STATUS_ENABLE) {
            return $this->fail('账号已被禁用，请联系超级管理员', 1002);
        }

        $admin->last_login_ip   = $this->request->ip();
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->save();

        Context::setAdmin($admin->toArray());
        $this->log('auth', 'login', '账号 ' . $username . ' 登录成功');

        $ttl   = (int) (env('JWT_TTL') ?: 7200);
        $token = Jwt::encode([
            'id'       => $admin->getAttr('id'),
            'username' => $admin->getAttr('username'),
            // 角色与类型：后台令牌必须显式声明，否则无法与 C 端令牌区分
            'role'     => Jwt::ROLE_ADMIN,
            'type'     => Jwt::TYPE_ACCESS,
        ], $ttl);

        $profile = $admin->toArray();

        return $this->success([
            'token'       => $token,
            'tokenType'   => 'Bearer',
            'expiresIn'   => $ttl,
            'admin'       => $profile,
            // 超级管理员返回 ['*']，其余返回已分配的权限 key
            'permissions' => Permission::responseFor($profile),
            // 按权限过滤后的菜单树（前端侧边栏据此渲染）
            'menus'       => Permission::menusFor($profile),
        ], '登录成功');
    }

    /**
     * 当前登录用户
     */
    public function profile()
    {
        $admin = Context::admin();
        if (!$admin) {
            return $this->fail('未登录', 401, null, 401);
        }

        return $this->success([
            'admin'       => $admin,
            // 超级管理员返回 ['*']，普通管理员返回其被分配的权限 key 列表
            'permissions' => Permission::responseFor($admin),
            'menus'       => Permission::menusFor($admin),
        ]);
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        $this->log('auth', 'logout', '退出登录');
        return $this->success(null, '已退出登录');
    }

    /**
     * 修改密码
     */
    public function password()
    {
        $data       = $this->params();
        $oldPassword = trim((string) ($data['old_password'] ?? ''));
        $newPassword = trim((string) ($data['new_password'] ?? ''));

        if (strlen($newPassword) < 6) {
            return $this->fail('新密码长度不能少于 6 位');
        }

        $admin = Admin::find(Context::adminId());
        if (!$admin || !password_verify($oldPassword, $admin->getAttr('password'))) {
            return $this->fail('原密码不正确');
        }

        $admin->password = $newPassword;
        $admin->save();

        $this->log('auth', 'password', '修改登录密码');

        return $this->success(null, '密码修改成功，请重新登录');
    }
}
