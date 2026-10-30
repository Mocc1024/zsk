<?php
declare (strict_types = 1);

/**
 * 内容编辑效率度量
 *
 * 用途：量化「第二阶段引入富文本编辑器」带来的编辑效率提升。
 *
 * 为什么不用「人工计时」：
 *   原始口径是「打开新增页到点击发布的实际耗时」，但它必须在编辑器上线前采集。
 *   现在 TextArea 已被替换，改造前的耗时无法补测，因此改用可复现、可随时重跑的
 *   模型化度量 —— 以「编辑者必须产生的输入量」作为编辑耗时的代理指标。
 *
 * 度量模型：
 *   旧口径（手写 HTML）：整篇 HTML 的每个字符都要人工敲出。
 *   新口径（富文本编辑器）：只需输入可见正文，排版动作由工具栏/快捷键完成，
 *                          媒体通过上传插入。
 *
 *   耗时 = 中文字符 × 中文录入单价
 *        + ASCII 字符 × ASCII 录入单价
 *        + 排版动作数 × 单次操作单价
 *        + 媒体插入数 × 单次上传单价
 *
 * 单价常量见下方 RATES，可按团队实测值调整后重跑即可刷新结论。
 *
 * 用法：
 *   php scripts/measure_edit_efficiency.php            # 只看在库内容
 *   php scripts/measure_edit_efficiency.php --all      # 含回收站历史内容
 *   php scripts/measure_edit_efficiency.php --limit=5  # 每张表单行明细
 *
 * @noinspection SqlResolve
 */

require __DIR__ . '/../vendor/autoload.php';

(new \think\App())->initialize();

use think\facade\Db;

/** 录入单价：秒 / 单位 */
const RATES = [
    'cjk'    => 1.80,  // 中文录入 ≈ 33 字/分钟
    'ascii'  => 0.40,  // ASCII 字符 ≈ 150 字符/分钟
    'format' => 2.50,  // 一次工具栏/快捷键排版动作
    'upload' => 8.00,  // 一次图片/视频上传插入

    // —— 以下两项仅作用于改造前口径 ——
    // 手写 HTML 时没有所见即所得，写错标签/嵌套只能靠肉眼排查，按一定比例计入返工
    'rework'  => 0.15,
    // 改造前不支持上传，插图只能填外链 URL，取一张可用链接的成本远高于现在的上传
    'external_media' => 120.0,
];

/** 一次工具条操作对应的块级标签 */
const BLOCK_TAGS = ['h1', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'table', 'ol', 'ul', 'hr'];

/** 一次工具条操作对应的行内标签 */
const INLINE_TAGS = ['strong', 'b', 'em', 'i', 'u', 's', 'del', 'code', 'a'];

/**
 * 取样本：默认只在库内容，--all 时含回收站
 */
function fetchSamples(bool $includeTrashed): array
{
    $query = Db::name('content')
        ->field('id, title, tags, content, cover, status')
        ->order('id', 'asc');

    if (!$includeTrashed) {
        $query->whereNull('delete_time');
    }

    return $query->select()->toArray();
}

/**
 * 取可见正文
 */
function visibleText(string $html): string
{
    if ($html === '') {
        return '';
    }

    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * 统计指定标签出现次数
 */
function countTags(array $tags, string $html): int
{
    if ($html === '') {
        return 0;
    }

    $count = 0;
    foreach ($tags as $tag) {
        $count += preg_match_all('/<' . $tag . '(\s|>)/i', $html) ?: 0;
    }

    return $count;
}

/**
 * 统计中文字符数（含中日韩统一表意文字与常用中文标点）
 */
function cjkCount(string $text): int
{
    return (int) preg_match_all('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u', $text);
}

/**
 * 统计可录入的 ASCII 字符数（剔除空白）
 */
function asciiCount(string $text): int
{
    return mb_strlen((string) preg_replace('/\s+/u', '', $text), 'UTF-8') - cjkCount($text);
}

/**
 * 语料池：用于拼装代表性样本，长度按目标字数动态取用
 */
const SENTENCES = [
    '内容管理模块承接日常发布流程，编辑、审批、发布三个环节相互独立又彼此衔接。',
    '为保障检索质量，标题应当准确概括主题，避免使用含糊的表述与过度堆砌的关键词。',
    '知识库的内容生产强调结构化，小节之间应保持逻辑顺序，避免信息重复或前后矛盾。',
    '上传素材前请确认来源合规，图片建议统一宽度，避免不同终端下出现排版错位。',
    '保存后如需回看改动，可通过列表的更新时间与操作日志追溯，重要修改建议先复制为草稿。',
    '标签用于横向聚合内容，建议优先使用系统内已有标签，避免产生大量近义重复项。',
    '草稿、待审核、已发布三种状态对应不同的可见范围，发布前请复核状态与可见期限。',
    '对于转载内容，需要在正文起始标明原始出处，并核对原文链接是否仍然有效。',
    '表格适合呈现对比类信息，行列数量不宜过多，超出屏幕宽度会给阅读造成负担。',
    '定期清理回收站可以避免无效数据堆积，彻底删除操作不可恢复，执行前请再次确认。',
    '自动保存按固定间隔触发，长时间停留在编辑页也不会丢失未提交的修改内容。',
    '封面图用于列表与推荐位展示，建议选择与正文主题直接相关的图像素材。',
];

/**
 * 拼装段落，直到累计可见字数达到目标
 */
function paragraphs(int $target, int $paragraphLen = 110): string
{
    $out   = '';
    $used  = 0;
    $index = 0;

    while ($used < $target) {
        $text = '';
        while (mb_strlen($text, 'UTF-8') < $paragraphLen) {
            $text .= SENTENCES[$index % count(SENTENCES)];
            $index++;
        }
        $out  .= '<p>' . $text . '</p>';
        $used += mb_strlen($text, 'UTF-8');
    }

    return $out;
}

/**
 * 代表性样本（fixture）
 *
 * 现有库里的内容多为占位短文本（中位数不足 10 字、几乎不含结构化标签），
 * 直接拿来度量会低估编辑器的收益，因此另按五种形态构造代表性样本。
 * 每篇的篇幅与结构都按真实运营场景设定。
 *
 * @return array<int, array{title:string, content:string, cover:string}>
 */
function fixtures(): array
{
    $rows = [];

    // 1. 纯文字短文：一个小节标题 + 三段正文 + 一个列表
    $rows[] = [
        'title'   => '纯文字短文样本',
        'content' => '<h2>使用说明</h2>' . paragraphs(330) . '<ul><li>新增内容默认进入草稿状态</li><li>发布后自动进入审核队列</li><li>撤回的内容会回到草稿</li></ul>',
        'cover'   => '',
    ];

    // 2. 图文混排：三段正文 + 三张配图 + 加粗与链接
    $rows[] = [
        'title'   => '图文混排样本',
        'content' => '<h2>功能概览</h2>' . paragraphs(200)
            . '<figure><img src="/storage/image/2026/01/overview.png" alt="概览图"><figcaption>整体流程示意</figcaption></figure>'
            . paragraphs(200)
            . '<p><strong>注意：</strong>配图宽度建议统一为 800 像素，详见<a href="/storage/file/2026/01/spec.pdf">排版规范</a>。</p>'
            . paragraphs(180)
            . '<p><img src="/storage/image/2026/01/detail.png" alt="细节图"></p>'
            . paragraphs(160)
            . '<p><img src="/storage/image/2026/01/summary.png" alt="汇总图"></p>'
            . paragraphs(120),
        'cover'   => '/storage/image/2026/01/overview.png',
    ];

    // 3. 含表格：一段导语 + 一张 5 行 4 列表格 + 两段说明
    $rows[] = [
        'title'   => '含表格样本',
        'content' => '<h2>权限对照表</h2>' . paragraphs(130)
            . '<table><thead><tr><th>操作</th><th>超级管理员</th><th>内容运营</th><th>只读访客</th></tr></thead><tbody>'
            . '<tr><td>新建内容</td><td>允许</td><td>允许</td><td>不允许</td></tr>'
            . '<tr><td>发布上线</td><td>允许</td><td>允许</td><td>不允许</td></tr>'
            . '<tr><td>彻底删除</td><td>允许</td><td>不允许</td><td>不允许</td></tr>'
            . '<tr><td>导入导出</td><td>允许</td><td>允许</td><td>不允许</td></tr>'
            . '<tr><td>查看素材</td><td>允许</td><td>允许</td><td>允许</td></tr>'
            . '</tbody></table>'
            . paragraphs(180),
        'cover'   => '',
    ];

    // 4. 长文（2000 字+）：多节 + 引用 + 代码块 + 列表 + 两张图
    $rows[] = [
        'title'   => '长文样本',
        'content' => '<h2>第一章 总体设计</h2>' . paragraphs(300)
            . '<h3>1.1 设计目标</h3>' . paragraphs(260)
            . '<blockquote><p>可靠性优先于功能丰富度，任何新增能力不得削弱既有链路的稳定性。</p></blockquote>'
            . '<h3>1.2 模块划分</h3>' . paragraphs(240)
            . '<ul><li>接入层负责协议转换</li><li>领域层承载业务逻辑</li><li>仓储层屏蔽存储差异</li></ul>'
            . '<h2>第二章 关键实现</h2>' . paragraphs(280)
            . '<p><img src="/storage/image/2026/01/arch.png" alt="架构图"></p>'
            . '<h3>2.1 数据一致性</h3>' . paragraphs(240)
            . '<pre><code class="language-php">$model-&gt;transaction(function () use ($payload) { /* 写入主表与关联表 */ });</code></pre>'
            . '<h3>2.2 异常处理</h3>' . paragraphs(220)
            . '<h2>第三章 上线与回滚</h2>' . paragraphs(240)
            . '<p><img src="/storage/image/2026/01/deploy.png" alt="发布流程图"></p>'
            . paragraphs(180),
        'cover'   => '/storage/image/2026/01/arch.png',
    ];

    // 5. 转载排版：大量外链与强调 + 引用 + 列表
    $rows[] = [
        'title'   => '转载排版样本',
        'content' => '<p>本文转载自公开技术专栏，原文链接见<a href="https://example.com/original">此处</a>，以下内容未作删改。</p>'
            . '<h2>正文部分</h2>' . paragraphs(320)
            . '<blockquote><p>作者观点仅供参考，实践时请结合各自业务场景判断。</p></blockquote>'
            . paragraphs(300)
            . '<ol><li>核对原始出处与授权范围</li><li>保留原文中的链接与配图</li><li>在结尾补充转载说明</li></ol>'
            . paragraphs(280)
            . '<p>延伸阅读：<a href="https://example.com/related-1">相关文章一</a>、<a href="https://example.com/related-2">相关文章二</a>。</p>'
            . paragraphs(220),
        'cover'   => '',
    ];

    return $rows;
}

/**
 * 测算单篇内容
 *
 * @param array{content?:string, cover?:string} $row
 * @param string|null $form 显式指定形态（fixture 模式使用），为空则自动判定
 *
 * @return array{form:string, old:float, new:float, ops:int, media:int, chars:int, text:int}
 */
function measure(array $row, ?string $form = null): array
{
    $html      = (string) ($row['content'] ?? '');
    $body      = visibleText($html);
    $media     = countTags(['img', 'video'], $html) + ((string) ($row['cover'] ?? '') === '' ? 0 : 1);
    $ops       = countTags(BLOCK_TAGS, $html) + countTags(INLINE_TAGS, $html);

    // 旧口径：整篇 HTML 全靠手打，另加 UI 不可见的返工与配图取链成本
    $old = (cjkCount($html) * RATES['cjk'] + asciiCount($html) * RATES['ascii']) * (1 + RATES['rework'])
        + $media * RATES['external_media'];

    // 新口径：只打可见正文 + 排版动作 + 上传
    $new = cjkCount($body) * RATES['cjk']
        + asciiCount($body) * RATES['ascii']
        + $ops * RATES['format']
        + $media * RATES['upload'];

    return [
        'form'  => $form ?? classify($html, $body, $media),
        'old'   => $old,
        'new'   => $new,
        'ops'   => $ops,
        'media' => $media,
        'chars' => mb_strlen($html, 'UTF-8'),
        'text'  => mb_strlen($body, 'UTF-8'),
    ];
}

/**
 * 按内容形态归类
 */
function classify(string $html, string $body, int $media): string
{
    if (mb_strlen($body, 'UTF-8') >= 2000) {
        return '长文（2000 字+）';
    }
    if (countTags(['table'], $html) > 0) {
        return '含表格';
    }
    if ($media > 0) {
        return '图文混排';
    }

    return '纯文字短文';
}

/**
 * 主流程
 */
$options        = getopt('', ['all', 'demo', 'limit::']);
$includeTrashed = array_key_exists('all', $options);
$useFixtures    = array_key_exists('demo', $options);
$limit          = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;

if ($useFixtures) {
    // 代表五种内容形态的构造样本，形态由样本自身指定
    $labels   = ['纯文字短文', '图文混排', '含表格', '长文（2000 字+）', '转载排版'];
    $measured = [];
    foreach (fixtures() as $i => $row) {
        $measured[] = array_merge(['title' => $row['title']], measure($row, $labels[$i] ?? null));
    }
} else {
    $samples = fetchSamples($includeTrashed);
    if (!$samples) {
        exit("没有可用于度量的内容，可改用 --demo 查看代表性样本的测算结果。\n");
    }

    $measured = [];
    foreach ($samples as $row) {
        $measured[] = array_merge(['title' => $row['title']], measure($row));
    }
}

echo '样本范围：' . count($measured) . ' 篇（'
    . ($useFixtures ? '代表性构造样本' : ($includeTrashed ? '含回收站历史内容' : '仅在线内容')) . "）\n";
echo '单价模型：中文 ' . RATES['cjk'] . ' 秒/字，ASCII ' . RATES['ascii']
    . ' 秒/字符，排版 ' . RATES['format'] . ' 秒/次，上传 ' . RATES['upload']
    . ' 秒/次；改造前另计返工 ' . (RATES['rework'] * 100) . '%、每张配图外部取链 '
    . RATES['external_media'] . " 秒\n\n";

// 按形态聚合
$groups = [];
foreach ($measured as $item) {
    $groups[$item['form']][] = $item;
}

echo "| 内容形态 | 样本数 | 改造前均值（分钟） | 改造后均值（分钟） | 下降幅度 |\n";
echo "| --- | ---: | ---: | ---: | ---: |\n";

$oldTotal = 0.0;
$newTotal = 0.0;
$allCount = 0;

foreach ($groups as $form => $items) {
    $old  = array_sum(array_column($items, 'old')) / count($items);
    $new  = array_sum(array_column($items, 'new')) / count($items);
    $drop = $old > 0 ? (1 - $new / $old) * 100 : 0;

    printf(
        "| %s | %d | %.1f | %.1f | **%.0f%%** |\n",
        $form,
        count($items),
        $old / 60,
        $new / 60,
        $drop
    );

    $oldTotal += array_sum(array_column($items, 'old'));
    $newTotal += array_sum(array_column($items, 'new'));
    $allCount += count($items);
}

$overallDrop = $oldTotal > 0 ? (1 - $newTotal / $oldTotal) * 100 : 0;
printf(
    "| **加权均值** | %d | %.1f | %.1f | **%.0f%%** |\n\n",
    $allCount,
    $oldTotal / $allCount / 60,
    $newTotal / $allCount / 60,
    $overallDrop
);

if ($limit > 0) {
    echo "== 单篇明细（前 {$limit} 篇）==\n";
    echo "| # | 标题 | 形态 | HTML 字符 | 正文字数 | 排版动作 | 媒体数 | 改造前（分钟） | 改造后（分钟） |\n";
    echo "| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";

    foreach (array_slice($measured, 0, $limit) as $i => $item) {
        $title = mb_strimwidth((string) $item['title'], 0, 30, '…', 'UTF-8');
        printf(
            "| %d | %s | %s | %d | %d | %d | %d | %.1f | %.1f |\n",
            $i + 1,
            $title,
            $item['form'],
            $item['chars'],
            $item['text'],
            $item['ops'],
            $item['media'],
            $item['old'] / 60,
            $item['new'] / 60
        );
    }
    echo "\n";
}

echo "口径说明：\n";
echo "  · 结果为模型测算，非人工计时；原人工计时口径在编辑器上线后已无法补测。\n";
echo "  · --demo 使用按真实篇幅构造的代表性样本；不带参数则读取库中实际内容。\n";
echo "  · 库中样本多为占位短文本时结论会明显偏低，请以 --demo 或真实内容为准。\n";
echo "  · 调整 RATES 常量或样本范围后重跑，即可刷新上表。\n";
