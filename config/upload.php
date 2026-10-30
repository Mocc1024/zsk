<?php
// +----------------------------------------------------------------------
// | 上传配置（第二阶段 M2.1 素材库使用）
// | 安全原则：扩展名 + MIME 双重白名单、大小上限、随机重命名、
// |           脚本类文件永不落此目录（M2.0 已配置上传目录禁止解析）
// +----------------------------------------------------------------------

return [
    // 各类型允许的扩展名、MIME 与大小上限（字节）
    'types' => [
        'image' => [
            'ext'      => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
            'mime'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],
            'max_size' => 5 * 1024 * 1024,
        ],
        'video' => [
            'ext'      => ['mp4', 'webm', 'mov'],
            'mime'     => ['video/mp4', 'video/webm', 'video/quicktime'],
            'max_size' => 50 * 1024 * 1024,
        ],
        'file'  => [
            'ext'      => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt'],
            'mime'     => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
                'application/x-rar-compressed',
                'text/plain',
            ],
            'max_size' => 20 * 1024 * 1024,
        ],
    ],

    // 单请求整体上传上限（字节），需与 php.ini 的 post_max_size / upload_max_filesize 匹配。
    // 注意：各类型的 max_size 也不能超过 php.ini 的 upload_max_filesize，
    // 否则文件会先被 PHP 层丢弃，只能拿到「上传失败」这类笼统提示。
    'max_total' => 50 * 1024 * 1024,

    // 存储子目录（位于 public/storage 之下）
    'dir' => [
        'image' => 'image',
        'video' => 'video',
        'file'  => 'file',
    ],

    // 日期分目录格式，最终路径：{type}/{Ymd}/{random}.{ext}
    'date_format' => 'Ymd',

    // 是否允许 SVG：SVG 可内嵌脚本，默认关闭
    'allow_svg' => false,
];
