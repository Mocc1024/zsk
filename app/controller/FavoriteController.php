<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ContentReader;
use app\service\Context;
use think\facade\Db;

/**
 * 收藏
 *
 * 两条纪律：
 *   1. 取消收藏必须物理删除 —— (user_id, content_id) 上有唯一索引，
 *      用来保证重复收藏是幂等的；改成软删除后，取消再收藏会因索引占位而失败。
 *   2. zsk_content.favorite_count 是冗余字段，必须与关联表在同一个事务里增减，
 *      否则列表页的收藏数会与实际不符（M3.8 有异步校正脚本兜底）。
 *   3. 收藏不看等级 —— 任何已登录用户都能收藏。等级只是成长标记，不是权限门槛，
 *      不要在这里加 level 判断。
 */
class FavoriteController extends BaseController
{
    /**
     * 我的收藏（分页）
     */
    public function index()
    {
        [$page, $limit] = $this->pageInfo();
        $userId = Context::userId();

        $query = Db::name('user_favorite')
            ->alias('f')
            ->field('f.id, f.content_id, f.folder, f.create_time, c.title, c.cover, c.summary, c.views, cat.name as category_name')
            ->join('content c', 'f.content_id = c.id', 'LEFT')
            ->join('category cat', 'c.category_id = cat.id', 'LEFT')
            ->where('f.user_id', $userId)
            ->whereNull('c.delete_time');

        $total = (int) (clone $query)->count();
        $list  = $query->order('f.id', 'desc')->page($page, $limit)->select()->toArray();

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 收藏（幂等）
     */
    public function add()
    {
        $contentId = (int) $this->input('content_id', 0);
        if ($contentId <= 0) {
            return $this->fail('参数错误');
        }
        if (!self::visible($contentId)) {
            return $this->fail('内容不存在或未发布', 404);
        }

        $userId = Context::userId();

        Db::transaction(function () use ($userId, $contentId) {
            $exists = Db::name('user_favorite')
                ->where('user_id', $userId)
                ->where('content_id', $contentId)
                ->find();

            if ($exists) {
                return;
            }

            Db::name('user_favorite')->insert([
                'user_id'     => $userId,
                'content_id'  => $contentId,
                'folder'      => trim((string) $this->input('folder', 'default')) ?: 'default',
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            Db::name('content')->where('id', $contentId)->inc('favorite_count')->update();
        });

        return $this->success(null, '已收藏');
    }

    /**
     * 取消收藏（物理删除）
     */
    public function remove($id = 0)
    {
        $contentId = (int) ($id ?: $this->input('content_id', 0));
        if ($contentId <= 0) {
            return $this->fail('参数错误');
        }

        $userId = Context::userId();

        Db::transaction(function () use ($userId, $contentId) {
            // 物理删除：唯一索引要用，不能软删
            $deleted = Db::name('user_favorite')
                ->where('user_id', $userId)
                ->where('content_id', $contentId)
                ->delete();

            if ($deleted) {
                Db::name('content')
                    ->where('id', $contentId)
                    ->where('favorite_count', '>', 0)
                    ->dec('favorite_count')
                    ->update();
            }
        });

        return $this->success(null, '已取消收藏');
    }

    /**
     * 是否已收藏
     */
    public function check()
    {
        $contentId = (int) $this->input('content_id', 0);
        if ($contentId <= 0) {
            return $this->fail('参数错误');
        }

        $exists = Db::name('user_favorite')
            ->where('user_id', Context::userId())
            ->where('content_id', $contentId)
            ->find();

        return $this->success(['favorited' => !empty($exists)]);
    }

    /**
     * 内容是否对外可见
     */
    private static function visible(int $contentId): bool
    {
        return (int) ContentReader::scope()->where('c.id', $contentId)->count() > 0;
    }
}
