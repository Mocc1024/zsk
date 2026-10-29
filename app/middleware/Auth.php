<?php
declare (strict_types = 1);

namespace app\middleware;

use app\model\Admin;
use app\service\Context;
use app\service\Jwt;
use app\service\Permission;
use Closure;
use think\Request;

/**
 * JWT 鉴权中间件
 */
class Auth
{
    public function handle(Request $request, Closure $next)
    {
        $authorization = (string) $request->header('Authorization', '');
        $token         = trim($authorization);
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        // 兼容通过 query / body 传递 token
        if ($token === '') {
            $token = (string) $request->param('token', '');
        }

        if ($token === '') {
            return json(['code' => 401, 'msg' => '缺少登录令牌，请重新登录', 'data' => null], 401);
        }

        $payload = Jwt::decode($token);
        if ($payload === null || empty($payload['id'])) {
            return json(['code' => 401, 'msg' => '登录令牌无效或已过期', 'data' => null], 401);
        }

        $admin = Admin::where('id', (int) $payload['id'])
            ->where('status', Admin::STATUS_ENABLE)
            ->find();

        if (!$admin) {
            return json(['code' => 401, 'msg' => '账号不存在或已被禁用', 'data' => null], 401);
        }

        Context::setAdmin($admin->toArray());

        // 接口级鉴权：按「请求方法 + 路径」匹配所需权限点，未匹配到的接口默认放行
        $required = Permission::matchRoute($request->method(), (string) $request->pathinfo());
        if ($required !== null && !Permission::can($required)) {
            return json(['code' => 403, 'msg' => '无权限执行该操作：' . $required, 'data' => null], 403);
        }

        return $next($request);
    }
}
