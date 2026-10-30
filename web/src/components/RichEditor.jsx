import { useEffect, useMemo, useRef, useState } from 'react';
import { message } from 'antd';
import { Editor, Toolbar } from '@wangeditor/editor-for-react';
import '@wangeditor/editor/dist/css/style.css';
// 必须在官方样式之后引入，否则覆盖不了它的默认规则
import './RichEditor.css';
import { uploadApi } from '../api';
import { UPLOAD_LIMITS, UPLOAD_TYPE_TEXT, formatFileSize } from '../utils/constants';

/**
 * 上传前校验大小：超限直接提示，避免白等一次请求
 */
function checkSize(file, type) {
  const limit = UPLOAD_LIMITS[type] ?? UPLOAD_LIMITS.file;
  if (file.size > limit) {
    message.error(
      `${UPLOAD_TYPE_TEXT[type] || '文件'}大小不能超过 ${formatFileSize(limit)}，当前 ${formatFileSize(file.size)}`
    );
    return false;
  }
  return true;
}

/**
 * 富文本编辑器（WangEditor 5）
 *
 * 正文传到后端后还会再经 HtmlFilter 白名单净化一次，
 * 这里不做额外清洗，避免前端与后端规则不一致导致内容被二次破坏。
 */
export default function RichEditor({ value = '', onChange, height = 480, placeholder = '请输入正文内容…' }) {
  const [editor, setEditor] = useState(null);
  const containerRef = useRef(null);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  const toolbarConfig = useMemo(() => ({}), []);

  const editorConfig = useMemo(
    () => ({
      placeholder,
      MENU_CONF: {
        // 不用 server + customInsert，改走 customUpload：
        // ① 请求统一走 axios 拦截器，令牌每次实时读取，刷新后不会带着空令牌上传
        // ② 后端返回的是 { code, msg, data }，不含 wangEditor 默认的 errno 字段，
        //    直接交给它解析会被判为失败
        // ③ 大小超限、格式不支持等原因由拦截器统一给出中文提示
        uploadImage: {
          maxFileSize: UPLOAD_LIMITS.image,
          maxNumberOfFiles: 10,
          allowedFileTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'],
          async customUpload(file, insertFn) {
            if (!checkSize(file, 'image')) {
              return;
            }
            const formData = new FormData();
            formData.append('file', file);
            formData.append('folder', 'editor');
            try {
              const res = await uploadApi.image(formData);
              insertFn(res.data.url, res.data.name || '', res.data.url);
              message.success('图片上传成功');
            } catch (e) {
              // 错误提示已由拦截器统一处理
            }
          },
        },
        uploadVideo: {
          maxFileSize: UPLOAD_LIMITS.video,
          allowedFileTypes: ['video/mp4', 'video/webm', 'video/quicktime'],
          async customUpload(file, insertFn) {
            if (!checkSize(file, 'video')) {
              return;
            }
            const formData = new FormData();
            formData.append('file', file);
            formData.append('folder', 'editor');
            try {
              const res = await uploadApi.video(formData);
              insertFn(res.data.url);
              message.success('视频上传成功');
            } catch (e) {
              // 错误提示已由拦截器统一处理
            }
          },
        },
      },
    }),
    []
  );

  // 卸载时销毁实例，避免内存泄漏
  useEffect(
    () => () => {
      if (editor) {
        editor.destroy();
      }
    },
    [editor]
  );

  // 全屏状态由 WangEditor 通过切换容器 class 表达，没有事件可用，
  // 这里监听 class 变化：全屏期间锁住页面滚动，卸载/销毁后务必还原
  useEffect(() => {
    const el = containerRef.current;
    if (!el) {
      return undefined;
    }

    const sync = () => {
      document.body.classList.toggle('rich-editor-fullscreen', el.classList.contains('w-e-full-screen-container'));
    };

    sync();
    const observer = new MutationObserver(sync);
    observer.observe(el, { attributes: true, attributeFilter: ['class'] });

    return () => {
      observer.disconnect();
      document.body.classList.remove('rich-editor-fullscreen');
    };
  }, []);

  const handleChange = (instance) => {
    // editor-for-react 各版本回调参数不同，这里两种都兼容
    const html =
      typeof instance?.getHtml === 'function'
        ? instance.getHtml()
        : typeof instance?.html === 'string'
          ? instance.html
          : '';
    onChangeRef.current?.(html);
  };

  return (
    // 高度走 CSS 变量而非内联样式：内联样式在全屏时无法被覆盖，会让编辑区保持原高度
    <div className="rich-editor" ref={containerRef} style={{ '--editor-height': `${height}px` }}>
      <Toolbar editor={editor} defaultConfig={toolbarConfig} mode="default" />
      <Editor
        defaultConfig={editorConfig}
        value={value}
        onCreated={setEditor}
        onChange={handleChange}
        mode="default"
      />
    </div>
  );
}
