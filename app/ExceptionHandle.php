<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类（API 统一 JSON 返回）
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if ($e instanceof ValidateException) {
            $error = $e->getError();
            $msg   = is_array($error) ? (string) reset($error) : (string) $error;
            return json(['code' => 422, 'msg' => $msg, 'data' => null], 200);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) {
            return json(['code' => 404, 'msg' => '数据不存在', 'data' => null], 200);
        }

        if ($e instanceof HttpException) {
            return json([
                'code' => $e->getStatusCode(),
                'msg'  => $e->getMessage() ?: '请求异常',
                'data' => null,
            ], $e->getStatusCode());
        }

        // 数据库等其它异常
        $this->report($e);
        $debug = (bool) env('APP_DEBUG', false);
        $msg   = $debug
            ? $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']'
            : '服务器内部错误，请稍后重试';

        return json(['code' => 500, 'msg' => $msg, 'data' => null], 200);
    }
}
