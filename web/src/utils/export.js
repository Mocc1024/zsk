/** 把后端导出的文本/二进制内容组装成文件下载 */
export function downloadExport(res, fallbackName = 'export.csv') {
  const { filename, mime, encoding, content } = res?.data || {};

  let blob;
  if (encoding === 'base64') {
    const binary = atob(content || '');
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i);
    }
    blob = new Blob([bytes], { type: mime || 'application/octet-stream' });
  } else {
    blob = new Blob([content || ''], { type: `${mime || 'text/plain'};charset=utf-8` });
  }

  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename || fallbackName;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}
