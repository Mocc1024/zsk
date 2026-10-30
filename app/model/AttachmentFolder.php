<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 素材分组模型
 */
class AttachmentFolder extends Model
{
    /** 系统内置分组名：素材的默认归属，不允许删除 */
    public const DEFAULT_NAME = 'default';

    /** 分组名最大长度，与 zsk_attachment.folder 的 varchar(30) 对齐 */
    public const MAX_LENGTH = 30;

    protected $name = 'attachment_folder';

    protected $hidden = ['delete_time'];
}
