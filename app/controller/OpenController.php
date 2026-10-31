<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\ContentReader;
use app\service\Context;
use think\facade\Db;

/**
 * 开放 API
 *
 * 调用方：第三方系统。鉴权走 AppKey + HMAC 签名（app\middleware\OpenAuth），
 * 不接受后台 JWT 也不接受 C 端用户令牌。
 *
 * 约定：
 *   1. 只返回已发布内容（口径统一在 ContentReader，下架立即不可见）
 *   2. 详情的正文输出前二次过 HtmlFilter
 *   3. 只用 GET + query 传参——签名只覆盖查询串，路由参数不参与签名
 */
class OpenController extends BaseController
{
    /**
     * 签名连通性自检
     */
    public function ping()
    {
        $app = Context::openApp();

        return $this->success([
            'appKey' => (string) ($app['app_key'] ?? ''),
            'name'   => (string) ($app['name'] ?? ''),
        ], 'pong');
    }

    /**
     * 分类列表（仅启用）
     */
    public function categories()
    {
        $list = Db::name('category')
            ->whereNull('delete_time')
            ->where('status', 1)
            // zsk_category 是平级结构，没有 pid —— 分类导航按 sort 排即可
            ->field('id, name, slug, description, sort')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->success(['list' => $list]);
    }

    /**
     * 标签列表
     */
    public function tags()
    {
        $list = Db::name('tag')
            ->whereNull('delete_time')
            ->where('status', 1)
            ->field('id, name, slug, count')
            ->order('count', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return $this->success(['list' => $list]);
    }

    /**
     * 内容列表（分页）
     * 参数：category_id / tag_id / keyword / sort(latest|hot) / page / limit
     */
    public function contents()
    {
        [$page, $limit] = $this->pageInfo();

        return $this->success(ContentReader::listForApi($this->params(), $page, $limit));
    }

    /**
     * 内容详情
     */
    public function content($id = 0)
    {
        $id = (int) ($id ?: $this->request->param('id'));
        $row = ContentReader::findOne($id);

        if (!$row) {
            return $this->fail('内容不存在或未发布', 404);
        }

        return $this->success(['content' => $row]);
    }
}
