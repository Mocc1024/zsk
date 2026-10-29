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
        Route::get('content/:id', '\app\controller\ContentController@read');
        Route::put('content/:id', '\app\controller\ContentController@update');
        Route::delete('content/:id', '\app\controller\ContentController@delete');
        Route::put('content/:id/status', '\app\controller\ContentController@setStatus');
        Route::put('content/:id/top', '\app\controller\ContentController@setTop');

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
