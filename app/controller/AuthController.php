<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\Admin;
use app\service\Context;
use app\service\Jwt;
use app\service\LoginGuard;
use app\service\Permission;
use app\service\Totp;
use think\facade\Cache;

/**
 * 登录认证
 *
 * 开启了两步验证（TOTP）的账号，登录分成两步：
 *   1. POST auth/login      账号密码正确 → 返回一次性票据 ticket（不发令牌）
 *   2. POST auth/totp/login 票据 + 6 位验证码 → 换正式令牌
 *
 * 第 1 步绝不能直接发令牌。否则「密码对了但没输验证码」也能拿到 token，
 * 二次验证就只剩前端那层摆设——攻击者绕过前端直接调接口即可。
 */
class AuthController extends BaseController
{
    /** 两步验证票据有效期（秒） */
    private const TICKET_TTL = 300;

    /** 恢复码数量 */
    private const RECOVERY_COUNT = 8;

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

        // 失败锁定：原本后台登录没有这道闸，加两步验证后必须有。
        // 6 位码只有 100 万种，攻击者一旦拿到密码就能无限次调接口猜码。
        $locked = LoginGuard::lockedSeconds($username);
        if ($locked > 0) {
            return $this->fail('连续输错次数过多，请在 ' . ceil($locked / 60) . ' 分钟后再试', 1003);
        }

        // 已删除的账号不允许登录
        $admin = Admin::where('username', $username)->whereNull('delete_time')->find();
        if (!$admin || !password_verify($password, $admin->getAttr('password'))) {
            LoginGuard::fail($username);
            return $this->fail('账号或密码错误', 1001, null, 200);
        }
        if ((int) $admin->getAttr('status') !== Admin::STATUS_ENABLE) {
            return $this->fail('账号已被禁用，请联系超级管理员', 1002);
        }

        // 密码对但账号开了两步验证：只发票据。
        // 这里不更新 last_login、不写登录成功日志——还没真正登进来。
        if ((int) $admin->getAttr('totp_enabled') === 1) {
            $ticket = bin2hex(random_bytes(24));
            Cache::set(self::ticketKey($ticket), [
                'id' => (int) $admin->getAttr('id'),
                'ip' => $this->request->ip(),
            ], self::TICKET_TTL);

            return $this->success([
                'needTotp'  => true,
                'ticket'    => $ticket,
                'expiresIn' => self::TICKET_TTL,
            ], '请输入验证器中的 6 位验证码');
        }

        LoginGuard::clear($username);

        return $this->success($this->issueToken($admin), '登录成功');
    }

    /**
     * 两步验证：票据 + 验证码换正式令牌
     */
    public function totpLogin()
    {
        $data   = $this->params();
        $ticket = trim((string) ($data['ticket'] ?? ''));
        $code   = trim((string) ($data['code'] ?? ''));

        if ($ticket === '') {
            return $this->fail('验证已失效，请重新登录', 1004);
        }
        if ($code === '') {
            return $this->fail('请输入验证码');
        }

        $payload = Cache::get(self::ticketKey($ticket));
        if (!is_array($payload)) {
            return $this->fail('验证已超时，请重新登录', 1004);
        }

        $admin = Admin::where('id', (int) ($payload['id'] ?? 0))->whereNull('delete_time')->find();
        if (!$admin || (int) $admin->getAttr('status') !== Admin::STATUS_ENABLE) {
            return $this->fail('账号不可用', 1002);
        }

        $username = (string) $admin->getAttr('username');

        $locked = LoginGuard::lockedSeconds($username);
        if ($locked > 0) {
            return $this->fail('连续输错次数过多，请在 ' . ceil($locked / 60) . ' 分钟后再试', 1003);
        }

        $secret = (string) $admin->getAttr('totp_secret');

        // 验证器动态码优先；失败再试恢复码（手机丢了只有这条路）
        if (!Totp::verify($secret, $code) && !$this->consumeRecoveryCode($admin, $code)) {
            LoginGuard::fail($username);
            return $this->fail('验证码错误', 1005);
        }

        // 票据一次性：换到令牌立即作废
        Cache::delete(self::ticketKey($ticket));
        LoginGuard::clear($username);

        return $this->success($this->issueToken($admin), '登录成功');
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

    /**
     * 修改个人资料（昵称 / 邮箱 / 头像）
     *
     * 只允许改这三项。登录账号、所属用户组、启用状态属于账号管理范畴，
     * 必须留在「管理员管理」里由超级管理员操作——否则普通管理员可以自行改组提权。
     */
    public function updateProfile()
    {
        $data = $this->params();

        $admin = Admin::find(Context::adminId());
        if (!$admin) {
            return $this->fail('账号不存在', 404);
        }

        $payload = [];

        if (array_key_exists('nickname', $data)) {
            $nickname = trim((string) $data['nickname']);
            if ($nickname === '') {
                return $this->fail('昵称不能为空');
            }
            if (mb_strlen($nickname) > 50) {
                return $this->fail('昵称不能超过 50 个字符');
            }
            $payload['nickname'] = $nickname;
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            // 允许清空（''）但不允许填一个格式错误的地址
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->fail('邮箱格式不正确');
            }
            $payload['email'] = $email;
        }

        if (array_key_exists('avatar', $data)) {
            $payload['avatar'] = trim((string) $data['avatar']);
        }

        if (!$payload) {
            return $this->success(null, '没有需要更新的数据');
        }

        $payload['update_time'] = date('Y-m-d H:i:s');
        $admin->save($payload);

        $this->log('auth', 'profile', '修改个人资料');

        return $this->success(null, '资料已更新');
    }

    // =========================================================
    // 两步验证（TOTP）
    // =========================================================

    /**
     * 两步验证状态
     */
    public function totpStatus()
    {
        $admin = Admin::find(Context::adminId());
        if (!$admin) {
            return $this->fail('未登录', 401, null, 401);
        }

        $left = 0;
        $raw  = (string) $admin->getAttr('totp_recovery');
        if ($raw !== '') {
            $list = json_decode($raw, true);
            $left = is_array($list) ? count($list) : 0;
        }

        return $this->success([
            'enabled'       => (int) $admin->getAttr('totp_enabled') === 1,
            'recovery_left' => $left,
        ]);
    }

    /**
     * 生成绑定密钥（此时还未启用）
     *
     * 密钥先落库，enable 时校验的就是用户刚刚扫进去的这把，
     * 中间不经过前端回传，避免被篡改成别的密钥。
     */
    public function totpSetup()
    {
        $admin = Admin::find(Context::adminId());
        if (!$admin) {
            return $this->fail('未登录', 401, null, 401);
        }

        if ((int) $admin->getAttr('totp_enabled') === 1) {
            return $this->fail('已启用两步验证，如需更换请先关闭');
        }

        $secret = Totp::generateSecret();
        $admin->totp_secret = $secret;
        $admin->save();

        return $this->success([
            'secret' => $secret,
            'uri'    => Totp::uri((string) $admin->getAttr('username'), $secret),
        ], '请用验证器扫描二维码');
    }

    /**
     * 校验一次动态码后启用，并返回恢复码（仅此一次）
     */
    public function totpEnable()
    {
        $data = $this->params();
        $code = trim((string) ($data['code'] ?? ''));

        $admin = Admin::find(Context::adminId());
        if (!$admin) {
            return $this->fail('未登录', 401, null, 401);
        }
        if ((int) $admin->getAttr('totp_enabled') === 1) {
            return $this->fail('两步验证已启用');
        }

        $secret = (string) $admin->getAttr('totp_secret');
        if ($secret === '') {
            return $this->fail('请先生成密钥');
        }

        $username = (string) $admin->getAttr('username');
        if (LoginGuard::lockedSeconds($username) > 0) {
            return $this->fail('尝试过于频繁，请稍后再试', 1003);
        }

        // 绑定这一步必须真验一次：否则用户扫错了 App、或者二维码没扫成功，
        // 要等下次退出登录才发现进不来，人被关在门外。
        if (!Totp::verify($secret, $code)) {
            LoginGuard::fail($username);
            return $this->fail('验证码不正确，请确认手机上当前显示的数字');
        }

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }

        $admin->totp_enabled  = 1;
        $admin->totp_recovery = json_encode(
            array_map(static fn (string $c): string => password_hash($c, PASSWORD_BCRYPT), $codes)
        );
        $admin->save();

        LoginGuard::clear($username);
        $this->log('auth', 'totp-enable', '启用两步验证');

        return $this->success([
            'recovery_codes' => $codes,
        ], '两步验证已启用，请妥善保存恢复码');
    }

    /**
     * 关闭两步验证
     */
    public function totpDisable()
    {
        $data     = $this->params();
        $password = (string) ($data['password'] ?? '');
        $code     = trim((string) ($data['code'] ?? ''));

        $admin = Admin::find(Context::adminId());
        if (!$admin) {
            return $this->fail('未登录', 401, null, 401);
        }
        if ((int) $admin->getAttr('totp_enabled') !== 1) {
            return $this->fail('当前未启用两步验证');
        }

        // 关闭必须验密码：令牌一旦泄漏，不能让对方直接把这道锁摘掉
        if (!password_verify($password, (string) $admin->getAttr('password'))) {
            return $this->fail('登录密码不正确');
        }

        if (!Totp::verify((string) $admin->getAttr('totp_secret'), $code)) {
            return $this->fail('验证码不正确');
        }

        $admin->totp_enabled  = 0;
        $admin->totp_secret   = '';
        $admin->totp_recovery = '';
        $admin->save();

        $this->log('auth', 'totp-disable', '关闭两步验证');

        return $this->success(null, '两步验证已关闭');
    }

    // =========================================================
    // 内部方法
    // =========================================================

    /**
     * 校验并消费一个恢复码
     */
    private function consumeRecoveryCode(Admin $admin, string $code): bool
    {
        $raw = (string) $admin->getAttr('totp_recovery');
        if ($raw === '') {
            return false;
        }

        $hashes = json_decode($raw, true);
        if (!is_array($hashes) || !$hashes) {
            return false;
        }

        $code = strtoupper(trim($code));

        foreach ($hashes as $index => $hash) {
            if (!password_verify($code, (string) $hash)) {
                continue;
            }

            // 用过即焚：恢复码是一次性的
            unset($hashes[$index]);
            $admin->totp_recovery = json_encode(array_values($hashes));
            $admin->save();

            $this->log('auth', 'totp-recovery', '使用恢复码登录，剩余 ' . count($hashes) . ' 个');

            return true;
        }

        return false;
    }

    /**
     * 签发登录令牌并返回完整的会话信息
     */
    private function issueToken(Admin $admin): array
    {
        $admin->last_login_ip   = $this->request->ip();
        $admin->last_login_time = date('Y-m-d H:i:s');
        $admin->save();

        Context::setAdmin($admin->toArray());
        $this->log('auth', 'login', '账号 ' . $admin->getAttr('username') . ' 登录成功');

        $ttl   = (int) (env('JWT_TTL') ?: 7200);
        $token = Jwt::encode([
            'id'       => $admin->getAttr('id'),
            'username' => $admin->getAttr('username'),
            // 角色与类型：后台令牌必须显式声明，否则无法与 C 端令牌区分
            'role'     => Jwt::ROLE_ADMIN,
            'type'     => Jwt::TYPE_ACCESS,
        ], $ttl);

        $profile = $admin->toArray();

        return [
            'token'       => $token,
            'tokenType'   => 'Bearer',
            'expiresIn'   => $ttl,
            'admin'       => $profile,
            // 超级管理员返回 ['*']，其余返回已分配的权限 key
            'permissions' => Permission::responseFor($profile),
            // 按权限过滤后的菜单树（前端侧边栏据此渲染）
            'menus'       => Permission::menusFor($profile),
        ];
    }

    private static function ticketKey(string $ticket): string
    {
        return 'totp_ticket:' . md5($ticket);
    }
}
