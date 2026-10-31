<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;

/**
 * 图形验证码（纯 GD 生成，不引第三方库）
 *
 * 存 Redis 而不是 session：前台 SPA 与后端是分离部署的，
 * session 依赖 Cookie 域与同源策略，跨域调试时很容易踩坑；
 * 用 captcha_id 显式回传则与传输方式无关。
 *
 * 校验规则：一次性 —— 无论输入对错，取出来就立即作废。
 * 否则同一个验证码可以被拿去反复试密码，验证码形同虚设。
 */
class Captcha
{
    /** 有效期（秒） */
    public const TTL = 60;

    /** 字符集：去掉 0/O/1/I/l 这类肉眼易混的字符 */
    private const CHARS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LENGTH = 4;

    /**
     * 生成验证码
     *
     * @return array ['captcha_id' => string, 'image' => data URI]
     */
    public static function make(): array
    {
        $id   = bin2hex(random_bytes(8));
        $code = self::randomCode();

        Cache::set(self::key($id), strtolower($code), self::TTL);

        return [
            'captcha_id' => $id,
            'image'      => self::image($code),
        ];
    }

    /**
     * 校验验证码（一次性）
     */
    public static function verify(string $id, string $input): bool
    {
        $id    = trim($id);
        $input = strtolower(trim($input));
        if ($id === '' || $input === '') {
            return false;
        }

        $key    = self::key($id);
        $stored = Cache::get($key);

        // 取出来就作废，不管这次校验有没有通过
        Cache::delete($key);

        if (!$stored) {
            return false;
        }

        return hash_equals((string) $stored, $input);
    }

    private static function key(string $id): string
    {
        return 'captcha:' . $id;
    }

    private static function randomCode(): string
    {
        $max   = strlen(self::CHARS) - 1;
        $chars = [];
        for ($i = 0; $i < self::LENGTH; $i++) {
            $chars[] = self::CHARS[random_int(0, $max)];
        }
        return implode('', $chars);
    }

    /**
     * 画一张验证码图，返回 data URI
     */
    private static function image(string $code): string
    {
        $width  = 120;
        $height = 40;

        $im = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($im, 245, 247, 250);
        imagefilledrectangle($im, 0, 0, $width, $height, $bg);

        // 干扰线
        for ($i = 0; $i < 6; $i++) {
            $line = imagecolorallocate($im, random_int(180, 225), random_int(180, 225), random_int(180, 225));
            imageline($im, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $line);
        }

        // 噪点
        for ($i = 0; $i < 90; $i++) {
            $dot = imagecolorallocate($im, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imagesetpixel($im, random_int(0, $width - 1), random_int(0, $height - 1), $dot);
        }

        // 字符：逐个错位绘制，纯英文数字不需要字体文件
        for ($i = 0; $i < strlen($code); $i++) {
            $color = imagecolorallocate($im, random_int(20, 90), random_int(60, 140), random_int(150, 235));
            imagestring($im, 5, 14 + $i * 24, random_int(6, 14), $code[$i], $color);
        }

        ob_start();
        imagepng($im);
        $binary = (string) ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($binary);
    }
}
