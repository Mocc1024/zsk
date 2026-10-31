<?php
use think\facade\Route;

// 健康检查
Route::get('/', function () {
    return json([
        'code' => 0,
        'msg'  => 'ZSK 内容管理后台 API 服务运行中',
        'data' => ['version' => '1.0.0', 'framework' => 'ThinkPHP 8'],
    ]);
});

Route::group('api', function () {
    // ---- 登录认证 ----
    Route::post('auth/login', '\app\controller\AuthController@login');

    // ---- 需要登录令牌 ----
    Route::group(function () {
        Route::get('auth/profile', '\app\controller\AuthController@profile');
        Route::post('auth/logout', '\app\controller\AuthController@logout');
        Route::post('auth/password', '\app\controller\AuthController@password');

        // 数据看板
        Route::get('dashboard', '\app\controller\DashboardController@index');

        // 内容管理
        Route::get('content', '\app\controller\ContentController@index');
        Route::post('content', '\app\controller\ContentController@save');
        Route::post('content/batch-delete', '\app\controller\ContentController@batchDelete');
        // 具体路径必须排在 content/:id 之前，否则会被 :id 抢先匹配
        Route::get('content/trash', '\app\controller\ContentController@trashed');
        Route::get('content/trash/:id', '\app\controller\ContentController@trashedRead');
        Route::post('content/autosave', '\app\controller\ContentController@autosave');
        Route::post('content/batch-restore', '\app\controller\ContentController@batchRestore');
        Route::post('content/batch-force-delete', '\app\controller\ContentController@batchForceDelete');
        Route::post('content/export', '\app\controller\ContentController@export');

        Route::get('content/:id', '\app\controller\ContentController@read');
        Route::put('content/:id', '\app\controller\ContentController@update');
        Route::delete('content/:id', '\app\controller\ContentController@delete');
        Route::put('content/:id/status', '\app\controller\ContentController@setStatus');
        Route::put('content/:id/top', '\app\controller\ContentController@setTop');
        Route::post('content/:id/restore', '\app\controller\ContentController@restore');
        Route::post('content/:id/force-delete', '\app\controller\ContentController@forceDelete');
        Route::post('content/:id/copy', '\app\controller\ContentController@copy');

        // 文件上传（素材库）
        Route::post('upload/image', '\app\controller\UploadController@image');
        Route::post('upload/video', '\app\controller\UploadController@video');
        Route::post('upload/file', '\app\controller\UploadController@file');

        // 素材分组（具体路径排在 attachment/:id 之前）
        Route::get('attachment/folders', '\app\controller\AttachmentFolderController@index');
        Route::post('attachment/folders', '\app\controller\AttachmentFolderController@save');
        Route::put('attachment/folders/:id', '\app\controller\AttachmentFolderController@update');
        Route::delete('attachment/folders/:id', '\app\controller\AttachmentFolderController@delete');

        // 素材库
        Route::get('attachment', '\app\controller\AttachmentController@index');
        Route::put('attachment/move', '\app\controller\AttachmentController@move');
        Route::put('attachment/:id', '\app\controller\AttachmentController@update');
        Route::delete('attachment/:id', '\app\controller\AttachmentController@delete');
        Route::post('attachment/batch-delete', '\app\controller\AttachmentController@batchDelete');

        // 标签管理
        Route::get('tag/options', '\app\controller\TagController@options');
        Route::get('tag', '\app\controller\TagController@index');
        Route::post('tag', '\app\controller\TagController@save');
        Route::put('tag/:id', '\app\controller\TagController@update');
        Route::delete('tag/:id', '\app\controller\TagController@delete');

        // 分类管理
        Route::get('category/options', '\app\controller\CategoryController@options');
        Route::get('category', '\app\controller\CategoryController@index');
        Route::post('category', '\app\controller\CategoryController@save');
        Route::get('category/:id', '\app\controller\CategoryController@read');
        Route::put('category/:id', '\app\controller\CategoryController@update');
        Route::delete('category/:id', '\app\controller\CategoryController@delete');
        Route::put('category/:id/status', '\app\controller\CategoryController@setStatus');

        // 权限菜单管理
        Route::get('permission/tree', '\app\controller\AuthRuleController@index');
        Route::get('permission', '\app\controller\AuthRuleController@index');
        Route::post('permission', '\app\controller\AuthRuleController@save');
        Route::put('permission/:id', '\app\controller\AuthRuleController@update');
        Route::put('permission/:id/status', '\app\controller\AuthRuleController@setStatus');
        Route::delete('permission/:id', '\app\controller\AuthRuleController@delete');

        // 用户组管理
        Route::get('group/options', '\app\controller\AuthGroupController@options');
        Route::get('group', '\app\controller\AuthGroupController@index');
        Route::post('group', '\app\controller\AuthGroupController@save');
        Route::get('group/:id/rules', '\app\controller\AuthGroupController@rules');
        Route::put('group/:id', '\app\controller\AuthGroupController@update');
        Route::put('group/:id/rules', '\app\controller\AuthGroupController@authorize');
        Route::put('group/:id/status', '\app\controller\AuthGroupController@setStatus');
        Route::delete('group/:id', '\app\controller\AuthGroupController@delete');

        // 管理员管理
        Route::get('admin', '\app\controller\AdminController@index');
        Route::post('admin', '\app\controller\AdminController@save');
        Route::put('admin/:id', '\app\controller\AdminController@update');
        Route::delete('admin/:id', '\app\controller\AdminController@delete');
        Route::put('admin/:id/status', '\app\controller\AdminController@setStatus');

        // 操作日志
        Route::get('log', '\app\controller\LogController@index');
        Route::delete('log/clear', '\app\controller\LogController@clear');

        // 开放平台（应用管理）
        Route::get('open-app', '\app\controller\OpenAppController@index');
        Route::post('open-app', '\app\controller\OpenAppController@save');
        Route::get('open-app/:id', '\app\controller\OpenAppController@read');
        Route::put('open-app/:id', '\app\controller\OpenAppController@update');
        Route::put('open-app/:id/status', '\app\controller\OpenAppController@status');
        Route::post('open-app/:id/rotate', '\app\controller\OpenAppController@rotate');

        // 用户管理（C 端用户，与后台管理员 AdminController 是两套）
        Route::get('user-manage', '\app\controller\UserManageController@index');
        Route::post('user-manage', '\app\controller\UserManageController@save');
        Route::get('user-manage/:id', '\app\controller\UserManageController@read');
        Route::put('user-manage/:id', '\app\controller\UserManageController@update');
        Route::put('user-manage/:id/status', '\app\controller\UserManageController@status');
        Route::post('user-manage/:id/reset-password', '\app\controller\UserManageController@resetPassword');
        Route::post('user-manage/:id/points', '\app\controller\UserManageController@points');
        Route::post('user-manage/:id/exp', '\app\controller\UserManageController@exp');
        Route::post('user-manage/:id/restore', '\app\controller\UserManageController@restore');

        // 等级规则
        Route::get('level', '\app\controller\LevelController@index');
        Route::post('level', '\app\controller\LevelController@save');
        Route::post('level/recalculate', '\app\controller\LevelController@recalculate');
        Route::put('level/:id', '\app\controller\LevelController@update');

        // 积分规则与统计（/points/rule 要注册在 /points/:id 之前，这里没有 :id 路由，顺序无碍）
        Route::get('points/rule', '\app\controller\PointConfigController@rules');
        Route::post('points/rule', '\app\controller\PointConfigController@saveRules');
        Route::get('points/stat', '\app\controller\PointConfigController@stat');

        // 评论审核与配置（config 必须注册在 :id 之前，否则会被当成 id 匹配）
        Route::get('comment-audit/config', '\app\controller\CommentAuditController@config');
        Route::put('comment-audit/config', '\app\controller\CommentAuditController@saveConfig');
        Route::get('comment-audit', '\app\controller\CommentAuditController@index');
        Route::put('comment-audit/:id/audit', '\app\controller\CommentAuditController@audit');
        Route::delete('comment-audit/:id', '\app\controller\CommentAuditController@delete');

        // 敏感词库
        Route::get('sensitive-word', '\app\controller\CommentAuditController@sensitiveWords');
        Route::post('sensitive-word', '\app\controller\CommentAuditController@saveSensitiveWord');
        Route::delete('sensitive-word/:id', '\app\controller\CommentAuditController@deleteSensitiveWord');

        // 举报处理
        Route::get('report', '\app\controller\ReportManageController@index');
        Route::put('report/:id/handle', '\app\controller\ReportManageController@handle');
    })->middleware(\app\middleware\Auth::class);
});

// ---- SEO ----
Route::get('sitemap.xml', '\app\controller\SeoController@sitemap');

// ---- C 端前台：/api/front/*（与 /api/open/* 分工见 ADR-011）----
// 免鉴权的读接口（首页 / 列表 / 详情 / 搜索）与注册登录在 M3.2 – M3.3 填充；
// 这里只先挂上需要用户登录的分组，用于验证三套鉴权互不串用。
Route::group('api/front', function () {
    // 免鉴权只读：前台首页 / 列表 / 详情 / 搜索 / 浏览上报
    Route::get('home', '\app\controller\FrontController@home');
    Route::get('categories', '\app\controller\FrontController@categories');
    Route::get('contents', '\app\controller\FrontController@contents');
    Route::get('content/:id', '\app\controller\FrontController@content');
    Route::get('search', '\app\controller\FrontController@search');
    Route::post('stat/view', '\app\controller\FrontController@statView');
    // 评论列表免鉴权：未登录也要能看
    Route::get('comments', '\app\controller\CommentController@index');
    // 等级规则免鉴权：成长体系要能展示给未登录用户
    Route::get('levels', '\app\controller\PointController@levels');

    // 认证：免鉴权（注册 / 登录 / 刷新 / 验证码）
    Route::post('auth/captcha', '\app\controller\UserAuthController@captcha');
    Route::post('auth/register', '\app\controller\UserAuthController@register');
    Route::post('auth/login', '\app\controller\UserAuthController@login');
    Route::post('auth/refresh', '\app\controller\UserAuthController@refresh');

    // 需要用户登录的接口
    Route::group(function () {
        // 令牌探活：前端启动时可用它判断本地 token 是否还有效
        Route::get('ping', '\app\controller\UserController@ping');
        Route::post('auth/logout', '\app\controller\UserAuthController@logout');

        // 个人中心
        Route::get('user/profile', '\app\controller\UserController@profile');
        Route::put('user/profile', '\app\controller\UserController@update');
        Route::post('user/avatar', '\app\controller\UserController@avatar');
        Route::post('user/password', '\app\controller\UserController@password');
        Route::post('user/deactivate', '\app\controller\UserController@deactivate');

        // 收藏
        Route::get('favorites', '\app\controller\FavoriteController@index');
        Route::get('favorite/check', '\app\controller\FavoriteController@check');
        Route::post('favorite', '\app\controller\FavoriteController@add');
        Route::delete('favorite/:id', '\app\controller\FavoriteController@remove');

        // 评论 / 点赞 / 举报
        Route::post('comment', '\app\controller\CommentController@save');
        Route::post('comment/:id/like', '\app\controller\CommentController@like');
        Route::delete('comment/:id/like', '\app\controller\CommentController@unlike');
        Route::post('report', '\app\controller\CommentController@report');

        // 签到 / 积分 / 经验
        Route::post('signin', '\app\controller\PointController@signin');
        Route::get('signin/status', '\app\controller\PointController@signinStatus');
        Route::get('points', '\app\controller\PointController@points');
        Route::get('exp', '\app\controller\PointController@exp');
    })->middleware(\app\middleware\UserAuth::class);
});

// ---- 开放 API：/api/open/*（AppKey + 签名，第三方调用）----
// 签名协议见 app\middleware\OpenAuth 的类注释
Route::group('api/open', function () {
    // 签名连通性自检：第三方接入时先调它确认密钥与算法正确
    Route::get('ping', '\app\controller\OpenController@ping');
    Route::get('categories', '\app\controller\OpenController@categories');
    Route::get('tags', '\app\controller\OpenController@tags');
    Route::get('contents', '\app\controller\OpenController@contents');
    Route::get('content/:id', '\app\controller\OpenController@content');
})->middleware(\app\middleware\OpenAuth::class);
