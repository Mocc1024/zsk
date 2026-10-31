<?php
declare (strict_types = 1);

/**
 * 清除前台内容缓存
 *
 * 用途：后台改动内容后想让前台立刻生效时执行。
 * 不主动清也没关系——列表缓存 TTL 只有 60 秒，最迟 60 秒后自动更新。
 *
 * 用法：php scripts/clear_content_cache.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

\app\service\ContentCache::flush();

echo "前台内容缓存已清除\n";
