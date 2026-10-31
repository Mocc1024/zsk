<?php
declare (strict_types = 1);

/**
 * 重建内容全文索引
 *
 * 为什么需要它：InnoDB 的全文索引是「增量 + 标记删除」的——
 * 内容被删改后，旧分词只是标记删除，不会立刻从索引里消失。
 * 攒多了以后，新发布的内容会出现「库里有、搜索搜不到」的情况
 * （MATCH ... AGAINST 相关度为 0）。这不是查询写错了，
 * 是索引本身脏了，只能重建。
 *
 * 什么时候该跑：
 *   · 后台搜不到刚发布的内容
 *   · 批量导入 / 大量删除内容之后
 *   · 也可以挂个低峰定时任务定期跑（内容量不大时很快）
 *
 * 注意：OPTIMIZE TABLE 会锁表，生产环境请在低峰期执行。
 *
 * 用法：
 *   php scripts/refresh_search_index.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$table = 'zsk_content';

echo "开始重建 {$table} 全文索引……\n";

$before = Db::query("SHOW INDEX FROM `{$table}` WHERE Index_type = 'FULLTEXT'");
if (!$before) {
    echo "该表没有 FULLTEXT 索引，无需重建。\n";
    exit(0);
}

$columns = array_unique(array_column($before, 'Column_name'));
echo '索引列：' . implode(', ', $columns) . "\n";

$start = microtime(true);
Db::execute("OPTIMIZE TABLE `{$table}`");
$cost = round(microtime(true) - $start, 2);

// 抽查：拿一条真实内容验证索引已可用
$probe = Db::query("SELECT id, title FROM `{$table}` WHERE delete_time IS NULL ORDER BY id DESC LIMIT 1");
if ($probe) {
    $id    = (int) $probe[0]['id'];
    $token = mb_substr((string) $probe[0]['title'], 0, 2);
    $row   = Db::query(
        "SELECT MATCH(title, summary, content) AGAINST (? IN BOOLEAN MODE) AS score
         FROM `{$table}` WHERE id = ?",
        [$token, $id]
    );
    $score = (float) ($row[0]['score'] ?? 0);
    printf("抽查 #%d 关键词「%s」相关度：%.4f（%s）\n", $id, $token, $score, $score > 0 ? '正常' : '仍为 0');
}

printf("完成，耗时 %s 秒\n", $cost);
