<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\OperationLog;
use think\facade\Db;

/**
 * 操作日志
 */
class LogController extends BaseController
{
    /**
     * 列表
     */
    public function index()
    {
        $this->checkPermission('log.list');

        [$page, $limit] = $this->pageInfo();
        $params = $this->params();

        $query = Db::name('operation_log');

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where('admin_name|description|action', 'like', '%' . $keyword . '%');
        }
        if (($params['module'] ?? '') !== '') {
            $query->where('module', (string) $params['module']);
        }
        $start = trim((string) ($params['start_date'] ?? ''));
        if ($start !== '') {
            $query->where('create_time', '>=', $start . ' 00:00:00');
        }
        $end = trim((string) ($params['end_date'] ?? ''));
        if ($end !== '') {
            $query->where('create_time', '<=', $end . ' 23:59:59');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        $moduleMap = [
            'auth'       => '认证',
            'content'    => '内容',
            'category'   => '分类',
            'admin'      => '管理员',
            'group'      => '用户组',
            'permission' => '权限菜单',
            'system'     => '系统',
        ];
        foreach ($list as &$item) {
            $item['module_text'] = $moduleMap[$item['module']] ?? $item['module'];
        }
        unset($item);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
            'modules'  => $moduleMap,
        ]);
    }

    /**
     * 清空日志（超级管理员）
     */
    public function clear()
    {
        $this->checkPermission('log.clear');

        Db::name('operation_log')->delete(true);
        $this->log('system', 'clear-log', '清空操作日志');
        return $this->success(null, '日志已清空');
    }
}
