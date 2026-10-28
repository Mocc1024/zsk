<?php
declare (strict_types = 1);

namespace app\model;

use app\service\Context;
use think\facade\Request;
use think\Model;

/**
 * 操作日志模型
 */
class OperationLog extends Model
{
    protected $name = 'operation_log';

    protected $updateTime = false;

    /**
     * 记录一条操作日志
     */
    public static function record(string $module, string $action, string $description): void
    {
        try {
            self::create([
                'admin_id'    => Context::adminId(),
                'admin_name'  => Context::adminName(),
                'module'      => $module,
                'action'      => $action,
                'description' => mb_substr($description, 0, 250),
                'ip'          => Request::ip(),
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志写入失败不影响主流程
        }
    }
}
