<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\HtmlFilter;
use app\service\SensitiveWordService;
use app\service\Setting;
use think\facade\Db;

/**
 * 后台：评论审核、评论配置与敏感词库
 *
 * 审核通过时要同步 zsk_content.comment_count 冗余计数——
 * 这条评论在待审期间没有计入，通过后才补上。
 */
class CommentAuditController extends BaseController
{
    private const STATUS_PENDING = 0;
    private const STATUS_PASSED  = 1;
    private const STATUS_REJECT  = 2;

    /**
     * 评论列表（按状态筛选）
     */
    public function index()
    {
        $this->checkPermission('comment.list');

        [$page, $limit] = $this->pageInfo();

        $query = Db::name('comment')
            ->alias('c')
            ->field('c.id, c.user_id, c.content_id, c.pid, c.content, c.status, c.like_count, c.ip, c.create_time, u.nickname, ct.title as content_title')
            ->join('user u', 'c.user_id = u.id', 'LEFT')
            ->join('content ct', 'c.content_id = ct.id', 'LEFT')
            // 软删除的评论不进任何列表（删除是置 delete_time，不过滤会一直显示）
            ->whereNull('c.delete_time');

        $status = $this->input('status', '');
        if ($status !== '' && $status !== null) {
            $query->where('c.status', (int) $status);
        }
        $keyword = trim((string) $this->input('keyword', ''));
        if ($keyword !== '') {
            $query->where('c.content|u.nickname', 'like', '%' . $keyword . '%');
        }
        // 按文章筛选：内容管理里点某篇文章的「评论」时用
        $contentId = (int) $this->input('content_id', 0);
        if ($contentId > 0) {
            $query->where('c.content_id', $contentId);
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('c.id', 'desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['status_text'] = self::statusText((int) $item['status']);
        }
        unset($item);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
            'pending'  => (int) Db::name('comment')->where('status', self::STATUS_PENDING)->whereNull('delete_time')->count(),
        ]);
    }

    /**
     * 审核：通过 / 驳回
     */
    public function audit($id = 0)
    {
        $this->checkPermission('comment.audit');

        $id     = (int) ($id ?: $this->request->param('id'));
        $result = (int) $this->input('result', self::STATUS_PASSED);

        if (!in_array($result, [self::STATUS_PASSED, self::STATUS_REJECT], true)) {
            return $this->fail('审核结果不合法');
        }

        $comment = Db::name('comment')->where('id', $id)->whereNull('delete_time')->find();
        if (!$comment) {
            return $this->fail('评论不存在', 404);
        }
        $comment = is_array($comment) ? $comment : $comment->toArray();

        $before = (int) $comment['status'];

        Db::transaction(function () use ($id, $result, $comment, $before) {
            Db::name('comment')->where('id', $id)->update([
                'status'      => $result,
                'update_time' => date('Y-m-d H:i:s'),
            ]);

            $contentId = (int) $comment['content_id'];

            // 待审 → 通过：补上冗余计数
            if ($before === self::STATUS_PENDING && $result === self::STATUS_PASSED) {
                Db::name('content')->where('id', $contentId)->inc('comment_count')->update();
            }
            // 通过 → 驳回：扣掉冗余计数
            if ($before === self::STATUS_PASSED && $result === self::STATUS_REJECT) {
                Db::name('content')
                    ->where('id', $contentId)
                    ->where('comment_count', '>', 0)
                    ->dec('comment_count')
                    ->update();
            }
        });

        $this->log('comment', 'audit', ($result === self::STATUS_PASSED ? '通过' : '驳回') . '评论 #' . $id);

        return $this->success(null, $result === self::STATUS_PASSED ? '已通过' : '已驳回');
    }

    /**
     * 删除评论（软删除）
     */
    public function delete($id = 0)
    {
        $this->checkPermission('comment.delete');

        $id = (int) ($id ?: $this->request->param('id'));

        $comment = Db::name('comment')->where('id', $id)->whereNull('delete_time')->find();
        if (!$comment) {
            return $this->fail('评论不存在', 404);
        }
        $comment = is_array($comment) ? $comment : $comment->toArray();

        Db::transaction(function () use ($id, $comment) {
            Db::name('comment')->where('id', $id)->update([
                'delete_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);

            // 原先已通过的评论被删掉，冗余计数要跟着减
            if ((int) $comment['status'] === self::STATUS_PASSED) {
                Db::name('content')
                    ->where('id', (int) $comment['content_id'])
                    ->where('comment_count', '>', 0)
                    ->dec('comment_count')
                    ->update();
            }
        });

        $this->log('comment', 'delete', '删除评论 #' . $id);

        return $this->success(null, '已删除');
    }

    /**
     * 编辑评论内容（管理员修正措辞 / 清理违规字句）
     *
     * 只改文本，不动审核状态，冗余计数不受影响；
     * 编辑后的文本重新过 HTML 白名单，与前台提交同一处理标准。
     */
    public function update($id = 0)
    {
        $this->checkPermission('comment.audit');

        $id   = (int) ($id ?: $this->request->param('id'));
        $text = trim((string) $this->input('content', ''));

        if ($text === '') {
            return $this->fail('评论内容不能为空');
        }
        if (mb_strlen($text, 'UTF-8') > 2000) {
            return $this->fail('评论内容过长');
        }

        $comment = Db::name('comment')->where('id', $id)->whereNull('delete_time')->find();
        if (!$comment) {
            return $this->fail('评论不存在', 404);
        }

        $text = HtmlFilter::clean($text);

        Db::name('comment')->where('id', $id)->update([
            'content'     => $text,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('comment', 'edit', '编辑评论 #' . $id);

        return $this->success(null, '已保存');
    }

    /**
     * 评论配置
     */
    public function config()
    {
        $this->checkPermission('comment.config');

        return $this->success([
            'enabled'     => Setting::int('comment.enabled', 1),
            'audit_first' => Setting::int('comment.audit_first', 0),
            'min_length'  => Setting::int('comment.min_length', 2),
            'max_length'  => Setting::int('comment.max_length', 500),
        ]);
    }

    /**
     * 保存评论配置
     */
    public function saveConfig()
    {
        $this->checkPermission('comment.config');

        $data = $this->params();

        if (isset($data['enabled'])) {
            Setting::set('comment', 'enabled', (int) $data['enabled'] ? '1' : '0');
        }
        if (isset($data['audit_first'])) {
            Setting::set('comment', 'audit_first', (int) $data['audit_first'] ? '1' : '0');
        }
        if (isset($data['min_length'])) {
            Setting::set('comment', 'min_length', (string) max(1, (int) $data['min_length']));
        }
        if (isset($data['max_length'])) {
            Setting::set('comment', 'max_length', (string) min(2000, max(1, (int) $data['max_length'])));
        }

        $this->log('comment', 'config', '更新评论配置');

        return $this->success(null, '配置已保存');
    }

    /**
     * 敏感词列表
     */
    public function sensitiveWords()
    {
        $this->checkPermission('comment.config');

        [$page, $limit] = $this->pageInfo();

        $query = Db::name('sensitive_word');
        $keyword = trim((string) $this->input('keyword', ''));
        if ($keyword !== '') {
            $query->where('word', 'like', '%' . $keyword . '%');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 新增敏感词（可批量，逗号/换行分隔）
     */
    public function saveSensitiveWord()
    {
        $this->checkPermission('comment.config');

        $data   = $this->params();
        $level  = (int) ($data['level'] ?? SensitiveWordService::LEVEL_REPLACE);
        $raw    = trim((string) ($data['word'] ?? ''));

        if ($raw === '') {
            return $this->fail('请输入敏感词');
        }

        $words = preg_split('/[,，\s]+/u', $raw) ?: [];
        $added = 0;

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }
            $exists = Db::name('sensitive_word')->where('word', $word)->find();
            if ($exists) {
                continue;
            }
            Db::name('sensitive_word')->insert([
                'word'        => $word,
                'level'       => $level,
                'replacement' => trim((string) ($data['replacement'] ?? '***')) ?: '***',
                'status'      => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            $added++;
        }

        // 词库变了，缓存必须失效，否则新词要等一小时才生效
        SensitiveWordService::refresh();

        $this->log('comment', 'sensitive-word', '新增敏感词 ' . $added . ' 个');

        return $this->success(['added' => $added], '已新增 ' . $added . ' 个');
    }

    /**
     * 编辑敏感词（修改词语 / 处理级别 / 替换文本）
     */
    public function updateSensitiveWord($id = 0)
    {
        $this->checkPermission('comment.config');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();
        $word = trim((string) ($data['word'] ?? ''));

        if ($word === '') {
            return $this->fail('请输入词语');
        }
        if (mb_strlen($word, 'UTF-8') > 50) {
            return $this->fail('词语过长');
        }

        $row = Db::name('sensitive_word')->where('id', $id)->find();
        if (!$row) {
            return $this->fail('敏感词不存在', 404);
        }

        // 词唯一：改成已存在的词会导致重复命中
        $dup = Db::name('sensitive_word')
            ->where('word', $word)
            ->where('id', '<>', $id)
            ->find();
        if ($dup) {
            return $this->fail('该词语已存在');
        }

        Db::name('sensitive_word')->where('id', $id)->update([
            'word'        => $word,
            'level'       => (int) ($data['level'] ?? SensitiveWordService::LEVEL_REPLACE),
            'replacement' => trim((string) ($data['replacement'] ?? '***')) ?: '***',
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        // 词库变了，缓存必须失效
        SensitiveWordService::refresh();

        $this->log('comment', 'sensitive-word', '编辑敏感词 #' . $id);

        return $this->success(null, '已保存');
    }

    /**
     * 删除敏感词
     */
    public function deleteSensitiveWord($id = 0)
    {
        $this->checkPermission('comment.config');

        $id = (int) ($id ?: $this->request->param('id'));
        Db::name('sensitive_word')->where('id', $id)->delete();
        SensitiveWordService::refresh();

        $this->log('comment', 'sensitive-word', '删除敏感词 #' . $id);

        return $this->success(null, '已删除');
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_PASSED => '已通过',
            self::STATUS_REJECT => '已驳回',
            default             => '待审核',
        };
    }
}
