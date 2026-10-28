<?php
declare (strict_types = 1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 跨域中间件（前后端分离）
 */
class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $headers = [
            'Access-Control-Allow-Origin'      => '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Headers'     => 'Authorization,Content-Type,X-Requested-With,Accept,Origin',
            'Access-Control-Allow-Methods'     => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
            'Access-Control-Max-Age'           => '86400',
        ];

        if ($request->isOptions()) {
            return response('', 204, $headers);
        }

        /** @var Response $response */
        $response = $next($request);

        foreach ($headers as $key => $value) {
            $response->header([$key => $value]);
        }

        return $response;
    }
}
