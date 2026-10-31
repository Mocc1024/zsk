<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ContentReader;
use app\service\Context;
use app\service\HtmlFilter;
use app\service\SensitiveWordService;
use app\service\Setting;
use think\facade\Db;

/**
 * 评论 / 点赞 / 举报
 *
 * 结构：楼中楼两级（pid 父评论、root_id 根评论），不做无限层级——
 * 无限层级需要递归查询或闭包表，对「文章评论」这种量级不值得。
 *
 * 评论 / 点赞不看等级：任何已登录用户都能发评论、点赞。等级只是成长标记，
 * 不是权限门槛。是否需审核只看 comment.audit_first 开关与敏感词命中，
 * 与用户等级无关 —— 早期种子数据写过「Lv3 免审核」，那是文案凭空承诺，代码从未实现。
 *
 * 审核：comment.audit_first 开启后新评论默认待审，只有 status=1 才对外可见。
 * 敏感词 3 级命中直接转待审（不直接丢弃，交给人工判断）。
 */
class CommentController extends BaseController
{
    /** 评论状态 */
    private const STATUS_PENDING = 0;
    private const STATUS_PASSED  = 1;

    /**
     * 评论列表（楼中楼）
     */
    public function index()
    {
        $contentId = (int) $this->input('content_id', 0);
        if ($contentId <= 0) {
            return $this->fail('参数错误');
        }

        [$page, $limit] = $this->pageInfo();

        $query = Db::name('comment')
            ->alias('c')
            ->field('c.id, c.user_id, c.pid, c.root_id, c.content, c.like_count, c.create_time, u.nickname, u.avatar, u.level')
            ->join('user u', 'c.user_id = u.id', 'LEFT')
            ->where('c.content_id', $contentId)
            ->where('c.pid', 0)
            ->where('c.status', self::STATUS_PASSED)
            ->whereNull('c.delete_time');

        $total = (int) (clone $query)->count();
        $tops  = $query->order('c.create_time', 'desc')->page($page, $limit)->select()->toArray();

        $rootIds = array_column($tops, 'id');
        $replies = $rootIds
            ? Db::name('comment')
                ->alias('c')
                ->field('c.id, c.user_id, c.pid, c.root_id, c.content, c.like_count, c.create_time, u.nickname, u.avatar, u.level')
                ->join('user u', 'c.user_id = u.id', 'LEFT')
                ->whereIn('c.root_id', $rootIds)
                ->where('c.pid', '>', 0)
                ->where('c.status', self::STATUS_PASSED)
                ->whereNull('c.delete_time')
                ->order('c.create_time', 'asc')
                ->select()
                ->toArray()
            : [];

        $likedIds = self::likedCommentIds(array_merge($rootIds, array_column($replies, 'id')));

        // 回复挂到各自的楼下，避免前端再做一次分组
        $grouped = [];
        foreach ($replies as $reply) {
            $reply['liked'] = in_array((int) $reply['id'], $likedIds, true);
            $grouped[(int) $reply['root_id']][] = $reply;
        }

        foreach ($tops as &$top) {
            $top['replies'] = $grouped[(int) $top['id']] ?? [];
            $top['liked']   = in_array((int) $top['id'], $likedIds, true);
        }
        unset($top);

        return $this->success([
            'list'     => $tops,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 发表评论
     */
    public function save()
    {
        if (!Setting::int('comment.enabled', 1)) {
            return $this->fail('评论功能已关闭');
        }

        $data      = $this->params();
        $contentId = (int) ($data['content_id'] ?? 0);
        $text      = trim((string) ($data['content'] ?? ''));
        $pid       = (int) ($data['pid'] ?? 0);

        if ($contentId <= 0) {
            return $this->fail('参数错误');
        }
        if (!self::visible($contentId)) {
            return $this->fail('内容不存在或未发布', 404);
        }

        $min = Setting::int('comment.min_length', 2);
        $max = Setting::int('comment.max_length', 500);
        $len = mb_strlen($text, 'UTF-8');
        if ($len < $min) {
            return $this->fail('评论至少需要 ' . $min . ' 个字');
        }
        if ($len > $max) {
            return $this->fail('评论不能超过 ' . $max . ' 个字');
        }

        // 父评论必须存在且属于同一内容，且只允许两级
        $rootId = 0;
        if ($pid > 0) {
            $parent = Db::name('comment')
                ->where('id', $pid)
                ->where('content_id', $contentId)
                ->whereNull('delete_time')
                ->find();
            if (!$parent) {
                return $this->fail('被回复的评论不存在');
            }
            $parent = is_array($parent) ? $parent : $parent->toArray();
            $rootId = (int) ($parent['root_id'] ?: $parent['id']);
        }

        // 敏感词：3 级命中转待审，2 级替换，1 级仅记录
        $filtered = SensitiveWordService::filter($text);
        $text     = $filtered['text'];
        $blocked  = $filtered['blocked'];

        // 正文再过一次 HTML 白名单：评论是用户输入，不能直接入库原样
        $text = HtmlFilter::clean($text);

        $auditFirst = Setting::int('comment.audit_first', 0);
        $status     = ($auditFirst || $blocked) ? self::STATUS_PENDING : self::STATUS_PASSED;

        $id = 0;
        Db::transaction(function () use (&$id, $contentId, $pid, $rootId, $text, $status) {
            $id = (int) Db::name('comment')->insertGetId([
                'user_id'     => Context::userId(),
                'content_id'  => $contentId,
                'pid'         => $pid,
                'root_id'     => $rootId,
                'content'     => $text,
                'status'      => $status,
                'ip'          => $this->request->ip(),
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);

            // 顶层评论的 root_id 指向自己，方便按楼聚合
            if ($rootId === 0) {
                Db::name('comment')->where('id', $id)->update(['root_id' => $id]);
            }

            // 冗余计数只统计已通过的
            if ($status === self::STATUS_PASSED) {
                Db::name('content')->where('id', $contentId)->inc('comment_count')->update();
            }
        });

        // 评论积分与经验（M3.6 记账），未通过审核的不发
        if ($status === self::STATUS_PASSED) {
            \app\service\PointService::gainForComment(Context::userId(), $id);
        }

        $msg = $blocked ? '评论包含敏感内容，已转人工审核' : ($status === self::STATUS_PENDING ? '评论已提交，等待审核' : '评论已发布');

        return $this->success(['id' => $id, 'status' => $status, 'blocked' => $blocked], $msg);
    }

    /**
     * 点赞（幂等）
     */
    public function like($id = 0)
    {
        $commentId = (int) ($id ?: $this->input('id', 0));
        if ($commentId <= 0) {
            return $this->fail('参数错误');
        }

        $userId = Context::userId();

        Db::transaction(function () use ($userId, $commentId) {
            $exists = Db::name('comment_like')
                ->where('user_id', $userId)
                ->where('comment_id', $commentId)
                ->find();

            if ($exists) {
                return;
            }

            Db::name('comment_like')->insert([
                'user_id'     => $userId,
                'comment_id'  => $commentId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            Db::name('comment')->where('id', $commentId)->inc('like_count')->update();
        });

        return $this->success(null, '已点赞');
    }

    /**
     * 取消点赞（物理删除：唯一索引要用，不能软删）
     */
    public function unlike($id = 0)
    {
        $commentId = (int) ($id ?: $this->input('id', 0));
        $userId    = Context::userId();

        Db::transaction(function () use ($userId, $commentId) {
            $deleted = Db::name('comment_like')
                ->where('user_id', $userId)
                ->where('comment_id', $commentId)
                ->delete();

            if ($deleted) {
                Db::name('comment')
                    ->where('id', $commentId)
                    ->where('like_count', '>', 0)
                    ->dec('like_count')
                    ->update();
            }
        });

        return $this->success(null, '已取消点赞');
    }

    /**
     * 举报（同一用户对同一对象只能举报一次）
     */
    public function report()
    {
        $data       = $this->params();
        $targetType = trim((string) ($data['target_type'] ?? ''));
        $targetId   = (int) ($data['target_id'] ?? 0);
        $reason     = trim((string) ($data['reason'] ?? ''));

        if (!in_array($targetType, ['comment', 'content'], true) || $targetId <= 0) {
            return $this->fail('参数错误');
        }
        if ($reason === '') {
            return $this->fail('请填写举报理由');
        }

        $userId = Context::userId();

        $exists = Db::name('user_report')
            ->where('user_id', $userId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->find();

        if ($exists) {
            return $this->fail('你已经举报过该内容，我们会尽快处理');
        }

        Db::name('user_report')->insert([
            'user_id'     => $userId,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'reason'      => mb_substr($reason, 0, 255, 'UTF-8'),
            'status'      => 0,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(null, '举报已提交，我们会尽快处理');
    }

    /**
     * 当前用户点赞过的评论
     */
    private static function likedCommentIds(array $commentIds): array
    {
        $userId = Context::userId();
        if ($userId <= 0 || !$commentIds) {
            return [];
        }

        return array_map('intval', Db::name('comment_like')
            ->where('user_id', $userId)
            ->whereIn('comment_id', $commentIds)
            ->column('comment_id'));
    }

    private static function visible(int $contentId): bool
    {
        return (int) ContentReader::scope()->where('c.id', $contentId)->count() > 0;
    }
}
