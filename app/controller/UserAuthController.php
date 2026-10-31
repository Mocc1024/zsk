<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\service\Captcha;
use app\service\Jwt;
use app\service\LoginGuard;
use app\service\TokenBlacklist;

/**
 * C 端用户认证（注册 / 登录 / 刷新 / 登出）
 *
 * 这套令牌与后台管理员完全独立：role=user，见 app\middleware\UserAuth。
 * 所有写操作都要过图形验证码，避免被脚本刷注册。
 */
class UserAuthController extends BaseController
{
    /** 注销冷却期（天）：期间可自助恢复，超期彻底不可登录 */
    public const COOLING_DAYS = 30;

    /**
     * 图形验证码
     */
    public function captcha()
    {
        return $this->success(Captcha::make());
    }

    /**
     * 注册
     */
    public function register()
    {
        $data = $this->params();

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirm  = (string) ($data['confirm_password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
            return $this->fail('用户名需为 4-20 位字母、数字或下划线');
        }
        if (!User::strongEnough($password)) {
            return $this->fail('密码至少 8 位，且需同时包含字母和数字');
        }
        if ($password !== $confirm) {
            return $this->fail('两次输入的密码不一致');
        }
        if (empty($data['agree'])) {
            return $this->fail('请先阅读并同意用户协议');
        }

        if (!Captcha::verify((string) ($data['captcha_id'] ?? ''), (string) ($data['captcha_code'] ?? ''))) {
            return $this->fail('验证码错误或已失效');
        }

        // 用户名被占用时，连同已注销（冷却期内）的一起判重——
        // 冷却期内用户名仍然属于原账号，不能被别人抢注
        $exists = User::where('username', $username)->find();
        if ($exists) {
            return $this->fail('该用户名已被占用');
        }

        $user = new User();
        $user->username        = $username;
        $user->password        = $password;
        $user->nickname        = $username;
        $user->status          = User::STATUS_NORMAL;
        $user->level           = 1;
        $user->last_login_ip   = $this->request->ip();
        $user->last_login_time = date('Y-m-d H:i:s');
        $user->save();

        return $this->success(
            array_merge($this->issueTokens($user->toArray()), ['user' => $this->profileOf($user->toArray())]),
            '注册成功'
        );
    }

    /**
     * 登录
     */
    public function login()
    {
        $data     = $this->params();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->fail('请输入账号和密码');
        }

        $locked = LoginGuard::lockedSeconds($username);
        if ($locked > 0) {
            return $this->fail('连续输错次数过多，请在 ' . ceil($locked / 60) . ' 分钟后再试', 1003);
        }

        if (!Captcha::verify((string) ($data['captcha_id'] ?? ''), (string) ($data['captcha_code'] ?? ''))) {
            return $this->fail('验证码错误或已失效');
        }

        // 不排除 delete_time：注销冷却期内要允许自助恢复
        $user = User::where('username', $username)->find();
        if (!$user || !password_verify($password, $user->getAttr('password'))) {
            $left = LoginGuard::fail($username);
            if ($left <= 0) {
                return $this->fail('连续输错 ' . LoginGuard::MAX_FAIL . ' 次，账号已锁定 15 分钟', 1003);
            }
            return $this->fail('账号或密码错误，还可尝试 ' . $left . ' 次', 1001);
        }

        // 关键字段一律用 getAttr() 取，不能用 toArray()：
        // User 模型把 delete_time 放进了 $hidden，toArray() 里根本没有这个键，
        // 用它判断会让「注销恢复」和「超期拦截」两条逻辑永远不生效。
        $deletedAt = $user->getAttr('delete_time');

        // 注销冷却期处理
        if (!empty($deletedAt)) {
            $stamp = strtotime((string) $deletedAt);
            if ($stamp !== false && time() - $stamp > self::COOLING_DAYS * 86400) {
                return $this->fail('该账号已注销，无法登录', 1004);
            }
            // 冷却期内登录视为撤销注销
            $user->delete_time = null;
        }

        if ((int) $user->getAttr('status') !== User::STATUS_NORMAL) {
            return $this->fail('账号已被禁用，请联系管理员', 1002);
        }

        LoginGuard::clear($username);

        $user->last_login_ip   = $this->request->ip();
        $user->last_login_time = date('Y-m-d H:i:s');
        // 这一次 save 同时落掉「清空 delete_time」与「更新登录信息」
        $user->save();

        // 到这里 delete_time 已确定，再取一次数组用于返回
        $row = $user->toArray();

        return $this->success(
            array_merge($this->issueTokens($row), ['user' => $this->profileOf($row)]),
            '登录成功'
        );
    }

    /**
     * 用 Refresh Token 换新的 Access Token
     *
     * 刷新令牌只能走请求体，不放 Authorization 头——
     * 头里那张是 Access Token，两套令牌不要共用同一个入口。
     */
    public function refresh()
    {
        $token   = (string) $this->input('refresh_token', $this->input('refreshToken', ''));
        $payload = Jwt::decode($token);

        if ($payload === null || empty($payload['id'])) {
            return $this->fail('刷新令牌无效或已过期', 401, null, 401);
        }
        if (Jwt::roleOf($payload) !== Jwt::ROLE_USER || Jwt::typeOf($payload) !== Jwt::TYPE_REFRESH) {
            return $this->fail('令牌类型不正确', 401, null, 401);
        }
        if (TokenBlacklist::has($token)) {
            return $this->fail('刷新令牌已失效，请重新登录', 401, null, 401);
        }

        $user = User::where('id', (int) $payload['id'])
            ->where('status', User::STATUS_NORMAL)
            ->whereNull('delete_time')
            ->find();

        if (!$user) {
            return $this->fail('用户不存在或已被禁用', 401, null, 401);
        }

        // 只换 Access，Refresh 仍按原 7 天有效期
        return $this->success([
            'token'     => Jwt::encode([
                'id'       => $user->getAttr('id'),
                'username' => $user->getAttr('username'),
                'role'     => Jwt::ROLE_USER,
                'type'     => Jwt::TYPE_ACCESS,
            ], Jwt::userAccessTtl()),
            'expiresIn' => Jwt::userAccessTtl(),
        ]);
    }

    /**
     * 登出：Access 与 Refresh 一并作废
     */
    public function logout()
    {
        $access = \app\middleware\UserAuth::bearer($this->request);
        if ($access !== '') {
            TokenBlacklist::add($access);
        }

        $refresh = (string) $this->input('refresh_token', $this->input('refreshToken', ''));
        if ($refresh !== '') {
            TokenBlacklist::add($refresh);
        }

        return $this->success(null, '已退出登录');
    }

    /**
     * 签发双令牌
     */
    private function issueTokens(array $user): array
    {
        $base = [
            'id'       => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'role'     => Jwt::ROLE_USER,
        ];

        return [
            'token'           => Jwt::encode($base + ['type' => Jwt::TYPE_ACCESS], Jwt::userAccessTtl()),
            'refreshToken'    => Jwt::encode($base + ['type' => Jwt::TYPE_REFRESH], Jwt::userRefreshTtl()),
            'tokenType'       => 'Bearer',
            'expiresIn'       => Jwt::userAccessTtl(),
            'refreshExpiresIn' => Jwt::userRefreshTtl(),
        ];
    }

    /**
     * 对外返回的用户信息（不含密码等敏感字段）
     */
    private function profileOf(array $user): array
    {
        unset($user['password'], $user['delete_time']);
        return $user;
    }

    /**
     * 密码强度：至少 8 位且字母数字兼有
     */
}
