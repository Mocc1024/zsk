<?php
declare (strict_types = 1);

namespace app;

use app\model\OperationLog;
use app\service\Permission;
use think\App;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 获取当前请求参数（同时兼容 JSON Body 与表单）
     */
    protected function params(): array
    {
        $data = $this->request->param();
        if (!is_array($data)) {
            $data = [];
        }
        $raw = $this->request->getContent();
        if (!empty($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }
        return $data;
    }

    /**
     * 获取指定参数
     */
    protected function input(string $key = '', $default = null)
    {
        $data = $this->params();
        if ($key === '') {
            return $data;
        }
        return $data[$key] ?? $default;
    }

    /**
     * 分页参数
     */
    protected function pageInfo(): array
    {
        $data  = $this->params();
        $page  = max(1, (int) ($data['page'] ?? 1));
        $limit = (int) ($data['limit'] ?? $data['pageSize'] ?? 10);
        $limit = min(max(1, $limit), 200);
        return [$page, $limit];
    }

    /**
     * 成功响应
     */
    protected function success($data = null, string $msg = '操作成功', int $code = 0)
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    /**
     * 失败响应
     */
    protected function fail(string $msg = '操作失败', int $code = 1, $data = null, int $httpCode = 200)
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data], $httpCode);
    }

    /**
     * 权限校验
     *
     * 无权限时抛 403，由 ExceptionHandle 统一输出 {"code":403,...}。
     * 超级管理员（role=admin）跳过校验，始终通过。
     *
     * @param string $key 权限点，如 content.delete，见 config/permission.php
     * @throws HttpException
     */
    protected function checkPermission(string $key): void
    {
        if (!Permission::can($key)) {
            throw new HttpException(403, '无权限执行该操作：' . $key);
        }
    }

    /**
     * 敏感字段的「变更」校验
     *
     * 场景：状态 / 置顶这类字段有独立的权限点，但它们是在同一个「编辑」表单里一起提交的。
     * 只校验编辑权限（content.update）会漏掉这里，形成越权——所以必须按「值是否变化」二次校验：
     *
     *   - 字段没提交，或值没变 → 直接放行（普通编辑权限即可，不被误伤）
     *   - 值发生了变化         → 额外要求对应权限点，无权限则 403
     *
     * @param array  $data       本次提交的数据
     * @param array  $old        数据库里的原始数据
     * @param string $field      字段名，如 status
     * @param string $permission 变更该字段所需的权限点，如 content.status
     * @param string $label      字段中文名，用于报错提示
     * @throws HttpException
     */
    protected function checkFieldChange(
        array $data,
        array $old,
        string $field,
        string $permission,
        string $label
    ): void {
        if (!array_key_exists($field, $data)) {
            return;
        }

        // 开关类字段提交的是 true/false，统一转成 1/0 再比较
        $normalize = static function ($value): int {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if ($value === null) {
                return 0;
            }
            return (int) $value;
        };

        if ($normalize($data[$field]) === $normalize($old[$field] ?? 0)) {
            return;
        }

        if (!Permission::can($permission)) {
            throw new HttpException(403, '无权限修改' . $label);
        }
    }

    /**
     * 记录操作日志
     */
    protected function log(string $module, string $action, string $description): void
    {
        OperationLog::record($module, $action, $description);
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

}
