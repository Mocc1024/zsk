<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\Context;
use think\facade\Db;

/**
 * 后台：举报处理
 */
class ReportManageController extends BaseController
{
    private const STATUS_PENDING  = 0;
    private const STATUS_HANDLED  = 1;
    private const STATUS_REJECTED = 2;

    /**
     * 列表（按状态筛选）
     */
    public function index()
    {
        $this->checkPermission('report.list');

        [$page, $limit] = $this->pageInfo();

        $query = Db::name('user_report')
            ->alias('r')
            ->field('r.*, u.nickname, u.username')
            ->join('user u', 'r.user_id = u.id', 'LEFT');

        $status = $this->input('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('r.status', (int) $status);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('r.status', 'asc')->order('r.id', 'desc')->page($page, $limit)->select()->toArray();

        // 被举报对象的快照：评论取正文，内容取标题
        foreach ($list as &$item) {
            $item['status_text'] = self::statusText((int) $item['status']);
            $item['target_text'] = self::targetText((string) $item['target_type'], (int) $item['target_id']);
        }
        unset($item);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
            'pending'  => (int) Db::name('user_report')->where('status', self::STATUS_PENDING)->count(),
        ]);
    }

    /**
     * 处理举报
     *
     * @param int $id
     * result: 1 已处理  2 已驳回
     */
    public function handle($id = 0)
    {
        $this->checkPermission('report.handle');

        $id     = (int) ($id ?: $this->request->param('id'));
        $result = (int) $this->input('result', self::STATUS_HANDLED);
        $note   = trim((string) $this->input('handle_result', ''));

        if (!in_array($result, [self::STATUS_HANDLED, self::STATUS_REJECTED], true)) {
            return $this->fail('处理结果不合法');
        }

        $report = Db::name('user_report')->where('id', $id)->find();
        if (!$report) {
            return $this->fail('举报不存在', 404);
        }

        Db::name('user_report')->where('id', $id)->update([
            'status'        => $result,
            'handle_result' => $note,
            'admin_id'      => Context::adminId(),
            'handle_time'   => date('Y-m-d H:i:s'),
            'update_time'   => date('Y-m-d H:i:s'),
        ]);

        $this->log('report', 'handle', ($result === self::STATUS_HANDLED ? '处理' : '驳回') . '举报 #' . $id);

        return $this->success(null, $result === self::STATUS_HANDLED ? '已处理' : '已驳回');
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_HANDLED  => '已处理',
            self::STATUS_REJECTED => '已驳回',
            default               => '待处理',
        };
    }

    /**
     * 取被举报对象的文本快照，方便运营直接判断
     */
    private static function targetText(string $type, int $id): string
    {
        if ($type === 'comment') {
            $row = Db::name('comment')->where('id', $id)->find();
            if (!$row) {
                return '（评论已删除）';
            }
            $row = is_array($row) ? $row : $row->toArray();
            return mb_substr((string) $row['content'], 0, 80, 'UTF-8');
        }

        if ($type === 'content') {
            $row = Db::name('content')->where('id', $id)->find();
            if (!$row) {
                return '（内容已删除）';
            }
            $row = is_array($row) ? $row : $row->toArray();
            return (string) $row['title'];
        }

        return '';
    }
}
