/**
 * 关键词高亮
 *
 * 用切片渲染而不是 dangerouslySetInnerHTML：
 * 搜索关键词是用户输入，拼进 <mark> 标签里就等于把输入原样当 HTML 执行，
 * 哪怕内容本身是安全的，这里也会开一个 XSS 口子。
 */
export default function Highlight({ text, keyword }) {
  const source = String(text ?? '');
  const kw = String(keyword ?? '').trim();

  if (kw === '') {
    return source;
  }

  const lowerSource = source.toLowerCase();
  const lowerKw = kw.toLowerCase();
  const parts = [];
  let cursor = 0;

  while (cursor < source.length) {
    const hit = lowerSource.indexOf(lowerKw, cursor);
    if (hit === -1) {
      parts.push(source.slice(cursor));
      break;
    }
    if (hit > cursor) {
      parts.push(source.slice(cursor, hit));
    }
    parts.push(<mark key={`${hit}-${cursor}`}>{source.slice(hit, hit + kw.length)}</mark>);
    cursor = hit + kw.length;
  }

  return <>{parts}</>;
}
