<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\service\UploadService;
use think\exception\ValidateException;
use think\file\UploadedFile;

/**
 * 文件上传：图片 / 视频 / 附件
 */
class UploadController extends BaseController
{
    /** 上传图片 */
    public function image()
    {
        return $this->receive('image');
    }

    /** 上传视频 */
    public function video()
    {
        return $this->receive('video');
    }

    /** 上传附件 */
    public function file()
    {
        return $this->receive('file');
    }

    /**
     * 统一接收上传
     */
    private function receive(string $type)
    {
        $this->checkPermission('attachment.upload');

        // 单请求整体大小上限：超限直接拒绝，避免超大请求白白占用带宽与 PHP 内存
        // 注意：Request::server() 的第二个参数必须是字符串，传整型会抛 TypeError
        $maxTotal = (int) config('upload.max_total', 0);
        if ($maxTotal > 0) {
            $length = (int) ((string) $this->request->server('CONTENT_LENGTH', '0') ?: 0);
            if ($length > $maxTotal) {
                return $this->fail('上传内容超出限制，单次最多 ' . UploadService::formatSize($maxTotal));
            }
        }

        $file = $this->request->file('file');
        if (!$file) {
            return $this->fail('请选择要上传的文件');
        }
        if (is_array($file)) {
            return $this->fail('一次只能上传一个文件');
        }
        if (!$file instanceof UploadedFile) {
            return $this->fail('上传数据异常');
        }

        $folder = trim((string) $this->input('folder', ''));

        try {
            $data = UploadService::store($file, $type, $folder);
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage());
        }

        $this->log('attachment', 'upload', '上传' . ($type === 'image' ? '图片' : '文件') . '：' . $data['name']);

        return $this->success($data, '上传成功');
    }
}
