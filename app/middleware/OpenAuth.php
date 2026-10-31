<?php
declare (strict_types = 1);

namespace app\middleware;

use app\model\OpenApp;
use app\service\Context;
use Closure;
use think\facade\Cache;
use think\Request;

/**
 * 开放 API 鉴权中间件（AppKey + HMAC 签名）
 *
 * 签名协议：
 *   参与签名的参数 = 请求的所有查询参数（sign 除外）
 *   1. 按参数名升序排序
 *   2. 拼成 k1=v1&k2=v2（值原样拼接，不做 urlencode，null 视为空串）
 *   3. sign = HMAC-SHA256(app_secret, 上面的字符串)，小写十六进制
 *   必传参数：appkey、timestamp（Unix 秒）、nonce（随机串）、sign
 *
 * 三道防线：
 *   · 时间窗：timestamp 与服务端偏差超过 ±300 秒直接拒，防止拿到旧请求重放
 *   · nonce：Redis SET NX 去重，同一 nonce 在 10 分钟内只能用一次
 *   · 限流：按 appkey 做秒级计数，超过该应用配额返回 429
 *
 * 注意：签名只覆盖查询串，不覆盖路由参数与请求体。
 * 因此开放 API 的接口设计只用 GET + query 传参（M3.1 遵循此约定）。
 */
class OpenAuth
{
    /** 时间窗（秒） */
    private const TIME_WINDOW = 300;

    /** nonce 有效期（秒） */
    private const NONCE_TTL = 600;

    public function handle(Request $request, Closure $next)
    {
        $params = $request->get();
        if (!is_array($params)) {
            $params = [];
        }

        $appKey    = trim((string) ($params['appkey'] ?? ''));
        $timestamp = trim((string) ($params['timestamp'] ?? ''));
        $nonce     = trim((string) ($params['nonce'] ?? ''));
        $sign      = trim((string) ($params['sign'] ?? ''));

        if ($appKey === '' || $timestamp === '' || $nonce === '' || $sign === '') {
            return self::deny(401, '缺少鉴权参数：appkey / timestamp / nonce / sign');
        }

        if (abs(time() - (int) $timestamp) > self::TIME_WINDOW) {
            return self::deny(401, 'timestamp 超出允许的时间窗（±' . self::TIME_WINDOW . '秒）');
        }

        $app = OpenApp::where('app_key', $appKey)
            ->where('status', OpenApp::STATUS_ENABLE)
            ->whereNull('delete_time')
            ->find();

        if (!$app) {
            return self::deny(401, 'appkey 无效或应用已停用');
        }

        $app = $app->toArray();

        if (!self::verifySign($params, (string) $app['app_secret'], $sign)) {
            return self::deny(401, '签名校验失败');
        }

        // 不做 instanceof \Redis 判断：换缓存驱动（如 predis）时只要方法签名一致就能继续工作
        $redis = self::redis();
        if (!is_object($redis) || !method_exists($redis, 'set') || !method_exists($redis, 'incr')) {
            return self::deny(503, '缓存服务不可用，无法完成防重放校验');
        }

        // nonce 防重放：SET NX，抢不到说明这个 nonce 已被用过
        $nonceKey = 'open_nonce:' . $appKey . ':' . $nonce;
        if (!$redis->set($nonceKey, 1, ['nx', 'ex' => self::NONCE_TTL])) {
            return self::deny(401, 'nonce 已被使用，请求疑似重放');
        }

        // 限流：秒级窗口计数
        $qps     = max(1, (int) ($app['qps'] ?? 10));
        $qpsKey  = 'open_qps:' . $appKey . ':' . date('YmdHis');
        $current = (int) $redis->incr($qpsKey);
        if ($current === 1) {
            $redis->expire($qpsKey, 2);
        }
        if ($current > $qps) {
            return self::deny(429, '请求频率超过该应用配额（' . $qps . ' 次/秒）');
        }

        // 路径白名单
        if (!self::pathAllowed((string) $app['allow_paths'], (string) $request->pathinfo())) {
            return self::deny(403, '该应用无权访问此接口');
        }

        $app['id'] = (int) $app['id'];
        Context::setOpenApp($app);

        return $next($request);
    }

    /**
     * 校验签名（恒定时间比较，避免时序侧信道）
     */
    private static function verifySign(array $params, string $secret, string $sign): bool
    {
        return hash_equals(self::sign($params, $secret), strtolower($sign));
    }

    /**
     * 计算签名：参数按名升序 → k=v 拼接 → HMAC-SHA256
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $pairs[] = $key . '=' . (string) $value;
        }

        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    /**
     * 路径白名单校验，allow_paths 为 '*' 表示全部放行
     */
    private static function pathAllowed(string $allowPaths, string $path): bool
    {
        $allowPaths = trim($allowPaths);
        if ($allowPaths === '' || $allowPaths === '*') {
            return true;
        }

        foreach (explode(',', $allowPaths) as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            if (str_starts_with($path, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取 Redis 原生句柄（需要 NX / INCR 这类 Cache 门面没有暴露的原子操作）
     *
     * @return object|null 取不到时返回 null，由调用方降级为拒绝请求
     */
    private static function redis()
    {
        try {
            return Cache::store('redis')->handler();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function deny(int $httpCode, string $msg)
    {
        return json(['code' => $httpCode, 'msg' => $msg, 'data' => null], $httpCode);
    }
}
