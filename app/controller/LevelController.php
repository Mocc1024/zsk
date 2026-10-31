<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\PointService;
use think\facade\Db;

/**
 * 后台：等级标识配置（名称 / 图标 / 经验门槛）
 *
 * 等级只是「用户成长到什么阶段」的标记，不参与任何权限判断 ——
 * 收藏、评论、点赞对已登录用户一律开放，改这里的配置不会影响任何功能可用性。
 * min_exp 只决定标记何时随经验变化。
 *
 * 改经验门槛后不会自动重算所有人的等级——
 * 全表重算要锁大量行，交给运营在需要时手动触发一次（recalculate）。
 */
class LevelController extends BaseController
{
    /**
     * 列表
     */
    public function index()
    {
        $this->checkPermission('level.list');

        $list = Db::name('user_level')
            ->order('level', 'asc')
            ->select()
            ->toArray();

        // 每级当前人数，方便运营判断门槛设置是否合理
        $counts = Db::name('user')
            ->whereNull('delete_time')
            ->group('level')
            ->column('COUNT(*)', 'level');

        foreach ($list as &$item) {
            $item['user_count'] = (int) ($counts[$item['level']] ?? 0);
        }
        unset($item);

        return $this->success(['list' => $list]);
    }

    /**
     * 新增
     */
    public function save()
    {
        $this->checkPermission('level.create');

        $data  = $this->params();
        $level = (int) ($data['level'] ?? 0);
        $name  = trim((string) ($data['name'] ?? ''));

        if ($level <= 0) {
            return $this->fail('请填写等级');
        }
        if ($name === '') {
            return $this->fail('请填写等级名称');
        }
        if (Db::name('user_level')->where('level', $level)->find()) {
            return $this->fail('该等级已存在');
        }

        Db::name('user_level')->insert([
            'level'       => $level,
            'name'        => $name,
            'icon'        => trim((string) ($data['icon'] ?? '')),
            'min_exp'     => max(0, (int) ($data['min_exp'] ?? 0)),
            'rights_desc' => trim((string) ($data['rights_desc'] ?? '')),
            'status'      => (int) ($data['status'] ?? 1),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $this->log('level', 'create', '新增等级 Lv.' . $level);

        return $this->success(null, '已新增');
    }

    /**
     * 编辑
     */
    public function update($id = 0)
    {
        $this->checkPermission('level.update');

        $id   = (int) ($id ?: $this->request->param('id'));
        $data = $this->params();

        $update = ['update_time' => date('Y-m-d H:i:s')];
        if (isset($data['name'])) {
            $update['name'] = trim((string) $data['name']);
        }
        if (isset($data['icon'])) {
            $update['icon'] = trim((string) $data['icon']);
        }
        if (isset($data['min_exp'])) {
            $update['min_exp'] = max(0, (int) $data['min_exp']);
        }
        if (isset($data['rights_desc'])) {
            $update['rights_desc'] = trim((string) $data['rights_desc']);
        }
        if (isset($data['status'])) {
            $update['status'] = (int) $data['status'];
        }

        Db::name('user_level')->where('id', $id)->update($update);
        $this->log('level', 'update', '编辑等级 #' . $id);

        return $this->success(null, '已保存');
    }

    /**
     * 按当前门槛重算所有用户等级
     */
    public function recalculate()
    {
        $this->checkPermission('level.update');

        $users = Db::name('user')->whereNull('delete_time')->field('id, exp')->select()->toArray();

        $updated = 0;
        foreach ($users as $user) {
            $level = PointService::levelFor((int) $user['exp']);
            if ((int) ($user['level'] ?? 0) !== $level) {
                Db::name('user')->where('id', (int) $user['id'])->update(['level' => $level]);
                $updated++;
            }
        }

        $this->log('level', 'recalculate', '重算用户等级，调整 ' . $updated . ' 人');

        return $this->success(['updated' => $updated], '已重算，调整 ' . $updated . ' 人');
    }
}
