<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 开放应用模型（AppKey + 签名鉴权的载体）
 *
 * 密钥轮换：直接改 app_secret 即可，旧签名立即失效。
 */
class OpenApp extends Model
{
    /** 状态 */
    public const STATUS_ENABLE  = 1;
    public const STATUS_DISABLE = 0;

    protected $name = 'open_app';

    protected $hidden = ['delete_time'];
}
