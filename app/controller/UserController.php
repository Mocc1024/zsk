<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\model\User;
use app\service\Context;
use app\service\Jwt;
use app\service\TokenBlacklist;
use app\service\UploadService;
use think\exception\ValidateException;
use think\file\UploadedFile;

/**
 * C 端用户：个人中心
 *
 * 全部接口走 UserAuth（role=user 令牌），后台管理员令牌进不来。
 */
class UserController extends BaseController
{
    /**
     * 令牌探活：返回当前登录用户，用于前端启动时判断令牌是否还有效
     */
    public function ping()
    {
        $user = Context::user();

        return $this->success([
            'userId'   => Context::userId(),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'level'    => (int) ($user['level'] ?? 1),
        ], 'pong');
    }

    /**
     * 个人资料
     */
    public function profile()
    {
        $user = Context::user() ?: [];
        unset($user['password'], $user['delete_time']);

        return $this->success(['user' => $user]);
    }

    /**
     * 修改资料：昵称 / 头像 / 性别 / 生日 / 简介
     */
    public function update()
    {
        $data = $this->params();

        $update = ['update_time' => date('Y-m-d H:i:s')];

        if (isset($data['nickname'])) {
            $nickname = trim((string) $data['nickname']);
            if ($nickname === '') {
                return $this->fail('昵称不能为空');
            }
            if (mb_strlen($nickname, 'UTF-8') > 20) {
                return $this->fail('昵称不能超过 20 个字');
            }
            $update['nickname'] = $nickname;
        }
        if (isset($data['avatar'])) {
            $update['avatar'] = trim((string) $data['avatar']);
        }
        if (isset($data['gender'])) {
            $update['gender'] = in_array((int) $data['gender'], [0, 1, 2], true) ? (int) $data['gender'] : 0;
        }
        if (isset($data['birthday'])) {
            $birthday = trim((string) $data['birthday']);
            $update['birthday'] = $birthday === '' ? null : $birthday;
        }
        if (isset($data['bio'])) {
            $update['bio'] = mb_substr(trim((string) $data['bio']), 0, 255, 'UTF-8');
        }

        User::where('id', Context::userId())->update($update);

        return $this->success(null, '资料已更新');
    }

    /**
     * 上传头像
     *
     * 走 UploadService 而不是复用后台的 /api/upload/image：
     * 那个接口要 attachment.upload 权限（属于后台素材库），
     * C 端用户没有、也不该有这个权限。
     */
    public function avatar()
    {
        $file = $this->request->file('file');
        if (!$file || is_array($file) || !$file instanceof UploadedFile) {
            return $this->fail('请选择要上传的图片');
        }

        try {
            $data = UploadService::store($file, 'image', 'avatar');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage());
        }

        $url = (string) ($data['url'] ?? '');
        if ($url === '') {
            return $this->fail('上传失败，请重试');
        }

        User::where('id', Context::userId())->update([
            'avatar'      => $url,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['url' => $url], '头像已更新');
    }

    /**
     * 修改密码
     *
     * 改完立刻把当前令牌拉黑：否则旧令牌在 2 小时内还能继续用，
     * 「改密后强制重新登录」就成了一句空话。
     */
    public function password()
    {
        $data        = $this->params();
        $oldPassword = (string) ($data['old_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');

        if (strlen($newPassword) < 8 || !preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            return $this->fail('新密码至少 8 位，且需同时包含字母和数字');
        }

        $user = User::find(Context::userId());
        if (!$user || !password_verify($oldPassword, $user->getAttr('password'))) {
            return $this->fail('原密码不正确');
        }

        $user->password = $newPassword;
        $user->save();

        $token = \app\middleware\UserAuth::bearer($this->request);
        if ($token !== '') {
            TokenBlacklist::add($token);
        }

        return $this->success(null, '密码已修改，请重新登录');
    }

    /**
     * 注销账号
     *
     * 软删除 + 30 天冷却：冷却期内重新登录即可自助恢复，
     * 超期后由清理任务物理删除。冷却期内用户名保持占用，
     * 防止别人抢注原账号。
     */
    public function deactivate()
    {
        $now = date('Y-m-d H:i:s');

        User::where('id', Context::userId())->update([
            'delete_time' => $now,
            'update_time' => $now,
        ]);

        $token = \app\middleware\UserAuth::bearer($this->request);
        if ($token !== '') {
            TokenBlacklist::add($token);
        }

        return $this->success([
            'deletedAt'   => $now,
            'recoverable' => date('Y-m-d H:i:s', time() + UserAuthController::COOLING_DAYS * 86400),
        ], '账号已注销，' . UserAuthController::COOLING_DAYS . ' 天内重新登录可恢复');
    }
}
