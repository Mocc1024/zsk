<?php
declare (strict_types = 1);

namespace app\service;

use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use HTMLPurifier;
use HTMLPurifier_Config;
use Throwable;

/**
 * HTML 净化入口
 *
 * 采用「双引擎」结构：
 *   1. 主路径 HTMLPurifier（ADR-002 首选方案，成熟的白名单引擎）
 *   2. 兜底路径 自研 DOM 白名单过滤（HTMLPurifier 不可用时自动接管）
 *
 * 两条路径共用同一份标签/属性白名单常量，保证行为尽量一致；
 * 调用方只依赖 clean()，引擎切换无需改动业务代码。
 *
 * 防护目标：
 *   1. 脚本类节点（script / iframe / svg ...）连同内容整块删除
 *   2. 非白名单标签只脱壳保留文本，不删除正文内容
 *   3. 事件属性（on*）、未知属性一律剥离
 *   4. 链接与图片地址仅允许 http/https/mailto/tel 与相对路径
 *   5. 注释、CDATA、处理指令一律移除
 *
 * 关于 style：编辑器的图片缩放、段落对齐、文字颜色都写在 style 里，
 * 一律剥离会导致「缩小图片后保存，再打开又是原尺寸」。
 * 因此放行 style，但取值必须收敛到 CSS_PROPS（属性白名单）+ 安全取值形状，
 * position / z-index 一类会破坏页面布局或造成点击劫持的属性不在其中。
 *
 * 注意：video / source 必须在白名单内 —— 编辑器支持上传本地视频，
 * 若把它们列入整块删除名单，正文里的视频会在保存时被静默清除。
 */
class HtmlFilter
{
    /** 允许的标签（小写） */
    private const TAGS = [
        'p', 'br', 'hr',
        'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'h1', 'h2', 'h3', 'h4',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'a', 'img', 'figure', 'figcaption',
        'span', 'div',
        'video', 'source',
    ];

    /** 整块删除的节点（含内部内容） */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'link', 'meta', 'base', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'svg', 'math', 'noscript', 'template',
        'frame', 'frameset', 'audio', 'track', 'canvas',
    ];

    /** 各标签允许的属性，未列出的标签不允许任何属性 */
    private const ATTRS = [
        'a'          => ['href', 'title', 'target'],
        'img'        => ['src', 'alt', 'title', 'width', 'height', 'style'],
        'table'      => ['border', 'cellpadding', 'cellspacing', 'width', 'style'],
        'th'         => ['colspan', 'rowspan', 'width', 'style'],
        'td'         => ['colspan', 'rowspan', 'width', 'style'],
        'pre'        => ['class', 'style'],
        'code'       => ['class', 'style'],
        'span'       => ['class', 'style'],
        'div'        => ['class', 'style'],
        'figure'     => ['class', 'style'],
        'figcaption' => ['class', 'style'],
        'blockquote' => ['class', 'style'],
        // 段落/标题/列表也需要 style：居中对齐、行高、缩进都写在上面
        'p'          => ['style'],
        'h1'         => ['style'],
        'h2'         => ['style'],
        'h3'         => ['style'],
        'h4'         => ['style'],
        'ul'         => ['style'],
        'ol'         => ['style'],
        'li'         => ['style'],
        'video'      => ['src', 'controls', 'poster', 'width', 'height', 'preload', 'style'],
        'source'     => ['src', 'type'],
    ];

    /**
     * style 里允许的 CSS 属性（小写）。
     *
     * 只放行「描述外观」的属性，放行它们不会改变元素在页面中的定位层级；
     * 刻意排除：position / top / left / right / bottom / z-index / transform /
     * filter / opacity / display / visibility / background(简写，可带 url()) /
     * content / behavior / cursor —— 这些可用于全屏遮挡、点击劫持或隐藏内容。
     */
    private const CSS_PROPS = [
        // 尺寸：图片缩放就靠这两个
        'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height',
        // 间距
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        // 对齐与排版
        'text-align', 'text-indent', 'line-height', 'letter-spacing',
        'vertical-align', 'float', 'clear',
        // 文字外观
        'color', 'background-color', 'font-size', 'font-weight', 'font-style',
        'text-decoration', 'font-family',
        // 边框与表格
        'border', 'border-width', 'border-style', 'border-color', 'border-radius',
        'border-collapse',
    ];

    /**
     * style 取值的合法形状：颜色 / 数值+单位 / 关键字 / 带引号的字体名
     * 配合下面的危险词黑名单使用，二者都要过
     */
    private const CSS_VALUE_PATTERN = '#\A(?:\s*(?:\#[0-9a-f]{3,8}|rgba?\([^()]*\)|-?\d+(?:\.\d+)?(?:px|%|em|rem|vw|vh|vmin|vmax|pt|pc|cm|mm|in|ex|ch|deg)?|"[^"]*"|\'[^\']*\'|[a-z][a-z0-9-]*|,|/)\s*)+\z#i';

    /** 允许的 URL 协议 */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** 供 HTMLPurifier 使用的白名单串 */
    private const PURIFIER_ALLOWED = [
        'p[style]', 'br', 'hr',
        'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'h1[style]', 'h2[style]', 'h3[style]', 'h4[style]',
        'ul[style]', 'ol[style]', 'li[style]',
        'blockquote[class|style]', 'pre[class|style]', 'code[class|style]',
        'table[border|cellpadding|cellspacing|width|style]', 'thead', 'tbody', 'tr',
        'th[colspan|rowspan|width|style]', 'td[colspan|rowspan|width|style]',
        'a[href|title|target|rel]',
        'img[src|alt|title|width|height|style]',
        'figure[class|style]', 'figcaption[class|style]', 'span[class|style]', 'div[class|style]',
        'video[src|controls|poster|width|height|preload|style]',
        'source[src|type]',
    ];

    /** HTMLPurifier 实例缓存 */
    private static ?HTMLPurifier $purifier = null;

    /** HTMLPurifier 是否可用（null = 尚未判定） */
    private static ?bool $available = null;

    /**
     * 净化 HTML 片段
     *
     * 优先走 HTMLPurifier；不可用或运行异常时回落到自研 DOM 过滤，
     * 保证在任何环境下正文都不会被原样写入。
     */
    public static function clean(?string $html): string
    {
        $html = $html === null ? '' : trim($html);
        if ($html === '') {
            return '';
        }

        // 去掉控制字符：既是绕过手段，也会让 DOM 解析异常
        $html = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html);

        $purified = self::purify($html);
        if ($purified !== null) {
            return $purified;
        }

        return self::cleanByDom($html);
    }

    /**
     * HTMLPurifier 主路径
     *
     * @return string|null 返回 null 表示引擎不可用，调用方应走兜底路径
     */
    private static function purify(string $html): ?string
    {
        if (self::$available === false) {
            return null;
        }

        try {
            $instance = self::resolvedPurifier();
            if ($instance === null) {
                return null;
            }

            return trim($instance->purify($html));
        } catch (Throwable) {
            // 引擎任何异常都不应影响内容保存
            self::$available = false;

            return null;
        }
    }

    /**
     * 构造（或复用）HTMLPurifier 实例
     */
    private static function resolvedPurifier(): ?HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        if (!class_exists(HTMLPurifier::class)) {
            self::$available = false;

            return null;
        }

        $cacheDir = app()->getRuntimePath() . 'purifier';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            self::$available = false;

            return null;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', $cacheDir);
        $config->set('HTML.Allowed', implode(',', self::PURIFIER_ALLOWED));
        $config->set('URI.AllowedSchemes', array_fill_keys(self::URL_SCHEMES, true));
        $config->set('Attr.AllowedRel', ['nofollow', 'noopener', 'noreferrer']);
        // 外链统一加 nofollow
        $config->set('HTML.Nofollow', true);
        // 这个模块既把 target 纳入 a 的合法属性，也会在 target=_blank 时自动补
        // rel="nofollow noreferrer noopener"，防止反向标签劫持
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.DefinitionID', 'zsk-content');
        // 改动上面的几何 / 属性定义后必须递增，否则会命中旧的定义缓存
        $config->set('HTML.DefinitionRev', 4);
        // 放行 style，但只允许「描述外观」的 CSS 属性：
        // 排除 position / z-index 等，避免正文内容能盖住整页做点击劫持
        $config->set('CSS.AllowedProperties', self::CSS_PROPS);

        // HTML5 标签（video / source / figure / u / s）不在 HTMLPurifier 默认定义里，需自行注册
        if ($definition = $config->maybeGetRawHTMLDefinition()) {
            $definition->addElement('u', 'Inline', 'Inline', 'Common');
            $definition->addElement('s', 'Inline', 'Inline', 'Common');
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Block', 'Flow', 'Common');
            $definition->addElement('source', 'Block', 'Empty', 'Common', [
                'src'  => 'URI',
                'type' => 'Text',
            ]);
            $definition->addElement('video', 'Block', 'Flow', 'Common', [
                'src'        => 'URI',
                'poster'     => 'URI',
                'controls'   => 'Text',
                'preload'    => 'Text',
                'width'      => 'Length',
                'height'     => 'Length',
            ]);
        }

        self::$available = true;
        self::$purifier  = new HTMLPurifier($config);

        return self::$purifier;
    }

    /**
     * 自研兜底：DOM 白名单过滤
     */
    private static function cleanByDom(string $html): string
    {
        $prev   = libxml_use_internal_errors(true);
        $doc    = new DOMDocument('1.0', 'UTF-8');
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($loaded === false) {
            return '';
        }

        // 1. 先整块删除危险节点
        foreach (self::DROP as $tag) {
            self::removeNodes($doc, $tag);
        }

        // 2. 再按白名单递归过滤（顺带清掉注释、CDATA、处理指令）
        self::walk($doc);

        // 3. 输出片段
        $out = '';
        foreach ($doc->childNodes as $child) {
            if ($child instanceof DOMProcessingInstruction || $child instanceof DOMComment) {
                continue;
            }
            $out .= $doc->saveHTML($child);
        }
        libxml_clear_errors();

        return trim($out);
    }

    /**
     * 删除文档中指定标签名的全部节点
     */
    private static function removeNodes(DOMDocument $doc, string $tag): void
    {
        $nodes = $doc->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            if ($node instanceof DOMNode && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * 递归遍历：非法标签脱壳，合法标签过滤属性
     */
    private static function walk(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);
                if (!in_array($tag, self::TAGS, true)) {
                    self::unwrap($child);
                    continue;
                }
                self::filterAttributes($tag, $child);
                self::walk($child);
                continue;
            }

            if (
                $child instanceof DOMComment
                || $child instanceof DOMProcessingInstruction
                || $child instanceof DOMCdataSection
            ) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * 脱壳：保留子节点，移除自身
     */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (!$parent) {
            return;
        }
        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /**
     * 属性白名单 + URL 协议校验
     */
    private static function filterAttributes(string $tag, DOMElement $element): void
    {
        $allowed = self::ATTRS[$tag] ?? [];

        $attrs = [];
        foreach ($element->attributes as $attr) {
            $attrs[] = $attr;
        }

        $dropElement = false;
        foreach ($attrs as $attr) {
            $rawName = $attr->nodeName;
            $name    = strtolower($rawName);

            // 事件属性与未列入白名单的属性一律剥离
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $element->removeAttribute($rawName);
                continue;
            }

            // style 只放行属性名是不够的，值里可能藏 position / expression / url()，
            // 必须逐条声明过滤；过滤后为空就整个属性去掉，不留空壳
            if ($name === 'style') {
                $safe = self::sanitizeStyle($attr->value);
                if ($safe === '') {
                    $element->removeAttribute($rawName);
                } else {
                    $element->setAttribute($rawName, $safe);
                }
                continue;
            }

            if (in_array($name, ['href', 'src'], true) && !self::isSafeUrl($attr->value)) {
                $element->removeAttribute($rawName);
                // 图片地址不可用 → 整张图没有保留价值
                if ($tag === 'img' && $name === 'src') {
                    $dropElement = true;
                }
            }
        }

        if ($tag === 'a') {
            if ($element->getAttribute('target') === '_blank') {
                $element->setAttribute('rel', 'nofollow noopener noreferrer');
            } elseif ($element->hasAttribute('href')) {
                $element->setAttribute('rel', 'nofollow');
            }
        }

        if ($dropElement) {
            self::unwrap($element);
        }
    }

    /**
     * 兜底路径：把 style 值收敛到 CSS_PROPS + 安全取值
     *
     * 与 HTMLPurifier 的 CSS.AllowedProperties 保持同一套属性白名单，
     * 保证「主引擎换了也不会改变安全结论」。
     */
    private static function sanitizeStyle(string $value): string
    {
        // 实体解码，防止 "color: &#106;avascript…" 这类变形
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '' || strlen($value) > 500) {
            return '';
        }

        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            // 只去掉控制字符，不能压掉空格，否则 "margin: 0 auto" 会被破坏
            $declaration = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $declaration));
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }

            [$prop, $cssValue] = explode(':', $declaration, 2);
            $prop     = strtolower(trim($prop));
            $cssValue = trim($cssValue);

            if (!in_array($prop, self::CSS_PROPS, true) || $cssValue === '') {
                continue;
            }
            // 危险词：expression / behavior 是 IE 脚本执行入口，url() 可外带请求，
            // 反斜杠与 &# 是常见的拆字绕过
            if (preg_match('/(expression|javascript|vbscript|behavior|@import|url\s*\(|\\\\|&#)/i', $cssValue)) {
                continue;
            }
            if (!preg_match(self::CSS_VALUE_PATTERN, $cssValue)) {
                continue;
            }

            // 同名声明后者覆盖前者，与 CSS 语义一致
            $kept[$prop] = $prop . ': ' . $cssValue;
        }

        return implode('; ', $kept);
    }

    /**
     * URL 安全性：仅允许指定协议与相对路径/锚点
     */
    private static function isSafeUrl(string $value): bool
    {
        // 压缩全部空白与控制字符，防止 "java\nscript:" 这类拆字绕过
        $clean = (string) preg_replace('/[\x00-\x20]+/', '', $value);
        if ($clean === '') {
            return false;
        }

        // 实体解码，防止 "&#106;avascript:" 绕过
        $decoded = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (str_starts_with($decoded, '#')) {
            return true;
        }

        // 没有协议 → 视为相对路径，放行
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $decoded, $matched)) {
            return true;
        }

        return in_array(strtolower($matched[1]), self::URL_SCHEMES, true);
    }
}
