<?php
// +----------------------------------------------------------------------
// | 权限节点定义（树形）
// +----------------------------------------------------------------------
// | key 命名规则：模块.动作（如 content.delete）
// | 只有叶子节点是真正的权限点，父节点仅用于菜单分组展示
// |
// | 菜单显示规则：某父节点下只要有一个叶子权限被勾选，该菜单即显示
// | 超级管理员：不读取本配置的勾选结果，直接拥有全部权限
// +----------------------------------------------------------------------

return [
    [
        'key'      => 'dashboard',
        'title'    => '数据看板',
        'children' => [
            ['key' => 'dashboard.view', 'title' => '查看看板'],
        ],
    ],
    [
        'key'      => 'content',
        'title'    => '内容管理',
        'children' => [
            ['key' => 'content.list', 'title' => '查看列表'],
            ['key' => 'content.create', 'title' => '新增内容'],
            ['key' => 'content.update', 'title' => '编辑内容'],
            ['key' => 'content.delete', 'title' => '删除内容'],
            ['key' => 'content.batch-delete', 'title' => '批量删除'],
            ['key' => 'content.status', 'title' => '发布/下架'],
            ['key' => 'content.top', 'title' => '置顶/取消置顶'],
        ],
    ],
    [
        'key'      => 'category',
        'title'    => '分类管理',
        'children' => [
            ['key' => 'category.list', 'title' => '查看列表'],
            ['key' => 'category.create', 'title' => '新增分类'],
            ['key' => 'category.update', 'title' => '编辑分类'],
            ['key' => 'category.delete', 'title' => '删除分类'],
            ['key' => 'category.status', 'title' => '启用/禁用'],
        ],
    ],
    [
        'key'      => 'log',
        'title'    => '操作日志',
        'children' => [
            ['key' => 'log.list', 'title' => '查看日志'],
            ['key' => 'log.clear', 'title' => '清空日志'],
        ],
    ],
    [
        'key'      => 'admin',
        'title'    => '管理员管理',
        'children' => [
            ['key' => 'admin.list', 'title' => '查看列表'],
            ['key' => 'admin.create', 'title' => '新增管理员'],
            ['key' => 'admin.update', 'title' => '编辑管理员'],
            ['key' => 'admin.delete', 'title' => '删除管理员'],
            ['key' => 'admin.status', 'title' => '启用/禁用'],
        ],
    ],
];
