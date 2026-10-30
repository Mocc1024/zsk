<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

/**
 * 标签模型
 */
class Tag extends Model
{
    protected $name = 'tag';

    protected $hidden = ['delete_time'];
}
