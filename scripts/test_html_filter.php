<?php
declare (strict_types = 1);

/**
 * HTML 净化回归测试
 *
 * 用途：改动 HtmlFilter 的标签/属性白名单后必须重跑，防止放宽过滤导致 XSS 漏洞。
 *
 * 同时对两条路径做回归：
 *   1. HTMLPurifier（主路径，生产环境生效）
 *   2. 自研 DOM 过滤（兜底路径，依赖缺失时自动接管）
 * 两条路径要求产出一致的安全结论，避免出现「主引擎换了就漏拦」的情况。
 *
 * 用法：php scripts/test_html_filter.php
 * 退出码：0 全部通过；1 存在不符预期的结果
 */

require __DIR__ . '/../vendor/autoload.php';

(new \think\App())->initialize();

use app\service\HtmlFilter;
use think\facade\Db;

/**
 * 两条待测引擎
 */
function engines(): array
{
    return [
        'HTMLPurifier 主路径' => static fn (string $html): string => HtmlFilter::clean($html),
        '自研 DOM 兜底' => function (string $html): string {
            $method = new ReflectionMethod(HtmlFilter::class, 'cleanByDom');
            $method->setAccessible(true);

            return $method->invoke(null, $html);
        },
    ];
}

/**
 * 用例：[输入, 不允许出现的片段, 必须保留的片段]
 */
function cases(): array
{
    return [
        // —— 注入类 ——
        ['<script>alert(1)</script><p>正文</p>', ['script', 'alert'], ['正文']],
        ['<p onclick="alert(1)">文本</p>', ['onclick', 'alert'], ['文本']],
        ['<p onmouseover="alert(1)">悬停</p>', ['onmouseover', 'alert'], ['悬停']],
        ['<a href="javascript:alert(1)">链接</a>', ['javascript', 'alert'], ['链接']],
        ['<a href="&#106;avascript:alert(1)">链接</a>', ['avascript', 'alert'], ['链接']],
        // 拆字绕过（制表符）：两条引擎的中和方式不同 —— HTMLPurifier 会把危险 URL
        // 百分号编码成惰性相对地址，自研版直接摘掉 href。只要不构成 javascript: 协议即可
        ['<a href="java&#x09;script:alert(1)">链接</a>', ['javascript'], ['链接']],
        ['<img src="/storage/a.jpg" onerror="alert(1)">', ['onerror', 'alert'], ['/storage/a.jpg']],
        ['<img src="javascript:alert(1)">', ['javascript', 'alert'], []],
        ['<iframe src="http://evil.com"></iframe><p>后</p>', ['iframe', 'evil'], ['后']],
        ['<svg/onload=alert(1)>x</svg>', ['svg', 'onload', 'alert'], []],
        ['<svg><script>alert(1)</script></svg><p>保留</p>', ['svg', 'script', 'alert'], ['保留']],
        ['<style>body{background:url(x)}</style><p>保留</p>', ['style', 'background'], ['保留']],
        // style 现已部分放行（图片缩放要用），所以这里不能断言「style 字符串不出现」，
        // 只能断言危险取值被清除：background 不在 CSS 白名单内，整条声明会被丢掉
        ['<div style="background:url(javascript:alert(1))">带样式的</div>', ['javascript', 'url('], ['带样式的']],
        ['<form action="x"><input value="1"></form>', ['form', 'input'], []],
        ['<!-- 注释 --><p>正文</p>', ['注释'], ['正文']],
        ['<marquee>跑马灯</marquee>文本', ['marquee'], ['文本']],
        ['<object data="x.swf"></object><p>后</p>', ['object', 'swf'], ['后']],
        ['<body onload=alert(1)>正文</body>', ['onload', 'alert'], ['正文']],
        ['<p>正常</p><base href="http://evil/">', ['base', 'evil'], ['正常']],

        // —— 保留类：确认正常排版不会被误伤 ——
        ['<p>中文内容<strong>加粗</strong></p>', [], ['中文内容', '加粗']],
        ['<p>斜体<em>强调</em>与<u>下划线</u></p>', [], ['强调', '下划线']],
        ['<table><tr><td colspan="2" rowspan="1">单元格</td></tr></table>', [], ['table', 'colspan', 'rowspan', '单元格']],
        ['<ul><li>项目一</li><li>项目二</li></ul>', [], ['项目一', '项目二']],
        ['<blockquote class="quote"><p>引用内容</p></blockquote>', [], ['引用内容']],
        ['<pre><code class="language-php">echo 1;</code></pre>', [], ['echo 1;']],

        // —— 链接安全 ——
        ['<a href="https://a.com" target="_blank">外链</a>',
            ['javascript'], ['https://a.com', '外链', '_blank', 'noopener', 'noreferrer']],
        ['<a href="https://a.com">普通外链</a>', ['javascript'], ['https://a.com', '普通外链', 'nofollow']],
        ['<a href="/relative/path">站内链接</a>', ['javascript'], ['/relative/path', '站内链接']],

        // —— 音视频：编辑器支持上传本地视频，这部分曾因白名单遗漏而被误删 ——
        ['<p>说明</p><video src="/storage/video/2026/a.mp4" controls="controls"></video>',
            [], ['video', '/storage/video/2026/a.mp4', 'controls', '说明']],
        ['<video><source src="/storage/video/x.mp4" type="video/mp4"></video>',
            [], ['source', '/storage/video/x.mp4']],
        ['<video src="javascript:alert(1)"></video>', ['javascript', 'alert'], []],
        ['<video src="/storage/v.mp4" onerror="alert(1)"></video>',
            ['onerror', 'alert'], ['/storage/v.mp4']],

        // —— 样式：编辑器缩放图片、居中对齐、文字颜色都写在 style 上，必须留得下来 ——
        ['<img src="/storage/a.jpg" style="width:300px;height:200px">',
            [], ['img', '/storage/a.jpg', 'width', '300px', 'height', '200px']],
        ['<p style="text-align:center">居中段落</p>', [], ['居中段落', 'text-align', 'center']],
        ['<span style="color:#ff0000">红字</span>', [], ['红字', 'color']],

        // —— 危险 CSS：能改定位层级或执行脚本的属性，一律不能留 ——
        ['<img src="/storage/a.jpg" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999">',
            ['position', 'z-index'], ['/storage/a.jpg']],
        ['<p style="background:url(javascript:alert(1))">文本</p>', ['javascript', 'url('], ['文本']],
        ['<div style="width:expression(alert(1))">文本</div>', ['expression', 'alert'], ['文本']],
        ['<span style="behavior:url(#default#time2)">文本</span>', ['behavior', '#default'], ['文本']],
    ];
}

$failures = 0;

foreach (engines() as $label => $runner) {
    echo "===== {$label} =====\n";
    $failedInEngine = 0;

    foreach (cases() as $index => [$input, $forbidden, $required]) {
        try {
            $output = $runner($input);
        } catch (Throwable $e) {
            $failedInEngine++;
            printf("  #%02d 执行异常：%s\n", $index + 1, $e->getMessage());
            continue;
        }

        $haystack  = strtolower($output);
        $leaked    = array_values(array_filter(
            $forbidden,
            static fn (string $needle): bool => str_contains($haystack, strtolower($needle))
        ));
        $missing   = array_values(array_filter(
            $required,
            static fn (string $needle): bool => !str_contains($haystack, strtolower($needle))
        ));

        if ($leaked === [] && $missing === []) {
            continue;
        }

        $failedInEngine++;
        printf("  #%02d 不符预期\n", $index + 1);
        echo "         输入：{$input}\n";
        echo "         输出：{$output}\n";
        if ($leaked !== []) {
            echo '         未拦截：' . implode('、', $leaked) . "\n";
        }
        if ($missing !== []) {
            echo '         被误删：' . implode('、', $missing) . "\n";
        }
    }

    printf(
        "  用例 %d 条，%s\n\n",
        count(cases()),
        $failedInEngine === 0 ? '全部通过' : "失败 {$failedInEngine} 条"
    );
    $failures += $failedInEngine;
}

// 真实的 Chinese内容保留一致性：确认净化器不会破坏已入库内容
echo "===== 存量内容复净化一致性 =====\n";
$rows = Db::name('content')->field('id, title, content')->limit(50)->select()->toArray();
$diff = 0;
foreach ($rows as $row) {
    $before = (string) $row['content'];
    $after  = HtmlFilter::clean($before);
    // 二次净化应收敛：已净化的内容再净化不应再变化
    if (HtmlFilter::clean($after) !== $after) {
        $diff++;
        echo "  #{$row['id']} 重复净化结果不稳定\n";
    }
}
printf("  抽查 %d 篇，重复净化不稳定 %d 篇\n\n", count($rows), $diff);
$failures += $diff;

if ($failures > 0) {
    echo "RESULT: FAILED（{$failures} 项不符）\n";
    exit(1);
}

echo "RESULT: PASSED\n";
exit(0);
