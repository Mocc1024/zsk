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
    })->middleware(\app\middleware\Auth::class);
});
