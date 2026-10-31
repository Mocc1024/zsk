<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

// 默认驱动：显式指定 CACHE_DRIVER 时优先；否则探测 Redis，可用就用，不可用自动回退 file。
//
// 为什么不能写死 'redis'：C 端 Token 黑名单、开放 API nonce 防重放与限流确实依赖 Redis，
// 但「缓存不可用」不该让后台连读个配置都 500 —— 配置读取、验证码、登录限流这些
// 用文件缓存完全够用。装了扩展不等于服务在跑，所以两件事都要查。
$pickDriver = static function (): string {
    $explicit = (string) env('CACHE_DRIVER', '');
    if ($explicit !== '') {
        return $explicit;
    }

    if (!extension_loaded('redis')) {
        return 'file';
    }

    // 0.2 秒探一次连通性，结果只在本次请求内复用，不会每次存缓存都连一次
    $fp = @fsockopen(
        (string) env('REDIS_HOST', '127.0.0.1'),
        (int) env('REDIS_PORT', 6379),
        $errno,
        $errstr,
        0.2
    );
    if ($fp) {
        fclose($fp);

        return 'redis';
    }

    return 'file';
};

return [
    'default' => $pickDriver(),

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // Redis 缓存（本机 6379，由小皮面板提供）
        'redis' => [
            'type'       => 'Redis',
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', 6379),
            'password'   => env('REDIS_PASSWORD', ''),
            'select'     => env('REDIS_SELECT', 0),
            'timeout'    => 0,
            'persistent' => false,
            'expire'     => 0,
            'prefix'     => env('REDIS_PREFIX', 'zsk:'),
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        // 更多的缓存连接
    ],
];
