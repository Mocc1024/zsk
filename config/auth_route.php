<?php
// +----------------------------------------------------------------------
// | 接口 → 权限点映射（用于中间件自动鉴权）
// | 格式：[请求方法, 路由模板, 权限标识]
// | 路由模板中的 :id 会匹配任意单段路径
// | 未在此映射的接口默认放行（如 auth/profile、auth/logout）
// +----------------------------------------------------------------------

return [
    // 数据看板
    ['GET', 'api/dashboard', 'dashboard.view'],

    // 内容管理
    ['GET', 'api/content', 'content.list'],
    ['POST', 'api/content', 'content.create'],
    ['POST', 'api/content/batch-delete', 'content.batch-delete'],
    ['GET', 'api/content/:id', 'content.list'],
    ['PUT', 'api/content/:id', 'content.update'],
    ['DELETE', 'api/content/:id', 'content.delete'],
    ['PUT', 'api/content/:id/status', 'content.status'],
    ['PUT', 'api/content/:id/top', 'content.top'],

    // 分类管理
    ['GET', 'api/category/options', 'category.list'],
    ['GET', 'api/category', 'category.list'],
    ['POST', 'api/category', 'category.create'],
    ['GET', 'api/category/:id', 'category.list'],
    ['PUT', 'api/category/:id', 'category.update'],
    ['DELETE', 'api/category/:id', 'category.delete'],
    ['PUT', 'api/category/:id/status', 'category.status'],

    // 操作日志
    ['GET', 'api/log', 'log.list'],
    ['DELETE', 'api/log/clear', 'log.clear'],

    // 管理员管理
    ['GET', 'api/admin/role-options', 'admin.list'],
    ['GET', 'api/admin', 'admin.list'],
    ['POST', 'api/admin', 'admin.create'],
    ['GET', 'api/admin/:id/permissions', 'admin.list'],
    ['PUT', 'api/admin/:id', 'admin.update'],
    ['DELETE', 'api/admin/:id', 'admin.delete'],
    ['PUT', 'api/admin/:id/status', 'admin.status'],

    // 用户组管理
    ['GET', 'api/group/options', 'group.list'],
    ['GET', 'api/group', 'group.list'],
    ['POST', 'api/group', 'group.create'],
    ['GET', 'api/group/:id/rules', 'group.list'],
    ['PUT', 'api/group/:id', 'group.update'],
    ['PUT', 'api/group/:id/rules', 'group.authorize'],
    ['DELETE', 'api/group/:id', 'group.delete'],
    ['PUT', 'api/group/:id/status', 'group.status'],

    // 权限菜单管理
    ['GET', 'api/permission/tree', 'permission.list'],
    ['GET', 'api/permission', 'permission.list'],
    ['POST', 'api/permission', 'permission.create'],
    ['PUT', 'api/permission/:id', 'permission.update'],
    ['DELETE', 'api/permission/:id', 'permission.delete'],
    ['PUT', 'api/permission/:id/status', 'permission.status'],
];
