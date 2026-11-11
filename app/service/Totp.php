<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;

/**
 * TOTP 两步验证（RFC 6238）
 *
 * 算法就是「共享密钥 + 当前时间片」做一次 HMAC-SHA1，取后 6 位：
 *   TOTP = HMAC-SHA1(key, floor(unix时间 / 30))[后 6 位十进制]
 *
 * 服务器和手机 App 持有同一把密钥、看同一个时钟，算出来的数字自然一致，
 * 所以整个过程不需要联网，也不依赖短信通道。
 *
 * 三个容易踩的点，这里都处理了：
 * 1. 时钟漂移 —— 校验时允许前后各 1 个时间片（±30 秒），服务器时间差几十秒不至于全员登不进；
 * 2. 重放 —— 同一个验证码在有效期内只接受一次，否则被肩窥后 30 秒内可以原样再用；
 * 3. 暴力枚举 —— 6 位码只有 100 万种，本类不做频控，调用方必须配合 LoginGuard 限制尝试次数。
 */
class Totp
{
    /** 验证码位数 */
    public const DIGITS = 6;

    /** 时间片长度（秒） */
    public const PERIOD = 30;

    /** 密钥长度（字节）—— 160 bit，与主流验证器一致 */
    private const SECRET_BYTES = 20;

    /** RFC 4648 Base32 字母表（无 0/1/8/9，避免手抄歧义） */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 允许的时间片偏差（±1 片 = ±30 秒） */
    private const WINDOW = 1;

    /**
     * 生成新密钥（Base32 字符串，可直接给 App 手动输入）
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * 生成 otpauth:// 链接，前端据此渲染二维码
     *
     * 格式是各验证器约定的，issuer 会显示在 App 的账户列表里，
     * 用「应用名:账号」的 label 形式，避免用户在多套系统间分不清。
     */
    public static function uri(string $account, string $secret, string $issuer = 'MCYY'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * 计算某一时刻的验证码
     *
     * @param int|null $timestamp 不传则取当前时间；自测时传入固定值比对 RFC 向量
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        return self::hotp($secret, $counter);
    }

    /**
     * 校验验证码
     *
     * @param int $window 允许的时间片偏差，默认 ±1
     */
    public static function verify(string $secret, string $code, int $window = self::WINDOW): bool
    {
        $secret = trim($secret);
        $code   = preg_replace('/\s+/', '', (string) $code);

        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', (string) $code)) {
            return false;
        }

        // 防重放：同一个码在有效期内只认一次。
        // 按「密钥 + 码」做键，换账号或换密钥互不影响。
        $usedKey = 'totp_used:' . md5($secret . ':' . $code);
        if (Cache::get($usedKey)) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), (string) $code)) {
                // 存 2 个时间片，覆盖这个码在前后窗口内仍然「算得出来」的整段时间
                Cache::set($usedKey, 1, self::PERIOD * 2 + self::PERIOD * $window);
                return true;
            }
        }

        return false;
    }

    /**
     * HOTP：RFC 4226 的动态截断
     */
    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }

        // 8 字节大端计数器
        $binary  = pack('J', $counter);
        $hash    = hash_hmac('sha1', $binary, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $value   = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= sprintf('%08b', ord($byte));
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $encoded): string
    {
        // 容忍用户手输时的大小写、空格、连字符
        $encoded = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $encoded));

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= sprintf('%05b', $pos);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $out .= chr(bindec($chunk));
        }

        return $out;
    }
}
