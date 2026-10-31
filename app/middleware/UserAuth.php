<?php
declare (strict_types = 1);

namespace app\middleware;

use app\model\User;
use app\service\Context;
use app\service\Jwt;
use app\service\TokenBlacklist;
use Closure;
use think\Request;

/**
 * C 端用户鉴权中间件
 *
 * 与 app\middleware\Auth（后台管理员）互为隔离：
 * 这里只认 role=user 的令牌，管理员令牌拿过来会被拒，反之亦然。
 * 这是「后台与 C 端不串用」这条约束的落地点，改动前请先确认交叉探针仍然全绿。
 */
class UserAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = self::bearer($request);
        if ($token === '') {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null], 401);
        }

        $payload = Jwt::decode($token);
        if ($payload === null || empty($payload['id'])) {
            return json(['code' => 401, 'msg' => '登录令牌无效或已过期', 'data' => null], 401);
        }

        // 角色必须是 C 端用户：管理员令牌在这里一律拒绝
        if (Jwt::roleOf($payload) !== Jwt::ROLE_USER) {
            return json(['code' => 401, 'msg' => '令牌类型不匹配，请使用用户登录令牌', 'data' => null], 401);
        }

        // Refresh Token 只能用来换 Access Token，不能直接访问业务接口
        if (Jwt::typeOf($payload) !== Jwt::TYPE_ACCESS) {
            return json(['code' => 401, 'msg' => '令牌类型不匹配，请使用 Access Token', 'data' => null], 401);
        }

        // 登出 / 封禁 / 改密后加入黑名单的令牌
        if (TokenBlacklist::has($token)) {
            return json(['code' => 401, 'msg' => '令牌已失效，请重新登录', 'data' => null], 401);
        }

        $user = User::where('id', (int) $payload['id'])
            ->where('status', User::STATUS_NORMAL)
            ->whereNull('delete_time')
            ->find();

        if (!$user) {
            return json(['code' => 401, 'msg' => '用户不存在或已被禁用', 'data' => null], 401);
        }

        Context::setUser($user->toArray());

        return $next($request);
    }

    /**
     * 提取 Bearer 令牌，兼容 query / body 传递
     */
    public static function bearer(Request $request): string
    {
        $token = trim((string) $request->header('Authorization', ''));
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        if ($token === '') {
            $token = trim((string) $request->param('token', ''));
        }
        return $token;
    }
}
