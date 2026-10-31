<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\OpenApp;
use think\facade\Db;

/**
 * 后台：开放应用管理
 *
 * 密钥处理原则：
 *   · 列表只返回脱敏后的 secret（前 8 位 + 掩码），避免整页能看到全部密钥
 *   · 新建与轮换时返回一次明文，之后不再可查——忘了只能再轮换一次
 *   · 不提供删除，停用即可：删掉应用会让调用方的历史签名记录无从追溯
 */
class OpenAppController extends BaseController
{
    /** 单次响应的 secret 掩码长度 */
    private const MASK_KEEP = 8;

    /**
     * 列表（分页）
     */
    public function index()
    {
        $this->checkPermission('open.list');

        [$page, $limit] = $this->pageInfo();

        $query = Db::name('open_app')->whereNull('delete_time');

        $keyword = trim((string) ($this->input('keyword', '')));
        if ($keyword !== '') {
            $query->where('name|app_key', 'like', '%' . $keyword . '%');
        }

        $total = (int) (clone $query)->count();
        $list  = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$item) {
            $item['app_secret']    = self::mask((string) $item['app_secret']);
            $item['status_text']   = (int) $item['status'] === OpenApp::STATUS_ENABLE ? '启用' : '停用';
            $item['last_used_time'] = $item['last_used_time'] ?: '';
        }
        unset($item);

        return $this->success([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $limit,
        ]);
    }

    /**
     * 详情
     */
    public function read($id = 0)
    {
        $this->checkPermission('open.list');

        $id  = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('open_app')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('应用不存在', 404);
        }

        $row['app_secret'] = self::mask((string) $row['app_secret']);

        return $this->success(['app' => $row]);
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('open.create');

        $data = $this->params();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->fail('请填写应用名称');
        }

        [$appKey, $appSecret] = self::generateKeys();

        $id = Db::name('open_app')->insertGetId([
            'name'         => $name,
            'app_key'      => $appKey,
            'app_secret'   => $appSecret,
            'allow_paths'  => trim((string) ($data['allow_paths'] ?? '*')) ?: '*',
            'qps'          => self::normalizeQps($data['qps'] ?? 10),
            'status'       => isset($data['status']) ? (int) $data['status'] : OpenApp::STATUS_ENABLE,
            'create_time'  => date('Y-m-d H:i:s'),
            'update_time'  => date('Y-m-d H:i:s'),
        ]);

        $this->log('open', 'create', '新增开放应用：' . $name);

        return $this->success([
            'id'         => $id,
            'app_key'    => $appKey,
            // 明文只在这一次响应里出现，请调用方妥善保存
            'app_secret' => $appSecret,
        ], '应用已创建，请保存密钥');
    }

    /**
     * 编辑（名称 / 允许路径 / QPS）
     */
    public function update($id = 0)
    {
        $this->checkPermission('open.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $row = Db::name('open_app')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('应用不存在', 404);
        }

        $update = ['update_time' => date('Y-m-d H:i:s')];
        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $update['name'] = trim((string) $data['name']);
        }
        if (isset($data['allow_paths'])) {
            $update['allow_paths'] = trim((string) $data['allow_paths']) ?: '*';
        }
        if (isset($data['qps'])) {
            $update['qps'] = self::normalizeQps($data['qps']);
        }

        Db::name('open_app')->where('id', $id)->update($update);
        $this->log('open', 'update', '编辑开放应用 #' . $id);

        return $this->success(null, '已保存');
    }

    /**
     * 启停
     */
    public function status($id = 0)
    {
        $this->checkPermission('open.status');

        $id     = (int) ($id ?: $this->request->param('id'));
        $status = (int) $this->input('status', 0) === OpenApp::STATUS_ENABLE
            ? OpenApp::STATUS_ENABLE
            : OpenApp::STATUS_DISABLE;

        Db::name('open_app')->where('id', $id)->update([
            'status'      => $status,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('open', 'status', ($status === OpenApp::STATUS_ENABLE ? '启用' : '停用') . '开放应用 #' . $id);

        return $this->success(null, $status === OpenApp::STATUS_ENABLE ? '已启用' : '已停用');
    }

    /**
     * 轮换密钥：换掉 app_secret，旧签名立即失效
     */
    public function rotate($id = 0)
    {
        $this->checkPermission('open.rotate');

        $id = (int) ($id ?: $this->request->param('id'));
        $row = Db::name('open_app')->where('id', $id)->whereNull('delete_time')->find();
        if (!$row) {
            return $this->fail('应用不存在', 404);
        }

        $secret = self::generateSecret();
        Db::name('open_app')->where('id', $id)->update([
            'app_secret'  => $secret,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('open', 'rotate', '轮换开放应用密钥 #' . $id);

        return $this->success([
            'app_key'    => $row['app_key'],
            'app_secret' => $secret,
        ], '密钥已轮换，旧签名立即失效');
    }

    private static function generateKeys(): array
    {
        return [self::generateAppKey(), self::generateSecret()];
    }

    private static function generateAppKey(): string
    {
        return 'ak_' . bin2hex(random_bytes(8));
    }

    private static function generateSecret(): string
    {
        return bin2hex(random_bytes(24));
    }

    private static function normalizeQps($value): int
    {
        return min(max(1, (int) $value), 1000);
    }

    /**
     * 密钥脱敏：只留前 8 位
     */
    private static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return substr($secret, 0, self::MASK_KEEP) . str_repeat('*', 12);
    }
}
