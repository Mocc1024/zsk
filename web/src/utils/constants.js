/** 内容状态 */
export const CONTENT_STATUS = [
  { label: '草稿', value: 0, color: 'default' },
  { label: '已发布', value: 1, color: 'green' },
  { label: '已下架', value: 2, color: 'red' },
];

export const contentStatusLabel = (value) =>
  CONTENT_STATUS.find((item) => item.value === Number(value))?.label ?? '未知';

export const contentStatusColor = (value) =>
  CONTENT_STATUS.find((item) => item.value === Number(value))?.color ?? 'default';

/** 通用启用状态 */
export const ENABLE_OPTIONS = [
  { label: '启用', value: 1 },
  { label: '禁用', value: 0 },
];

/**
 * 素材上传大小上限（字节）
 * 必须与后端 config/upload.php 的 types.*.max_size 保持一致，
 * 前端做前置拦截，后端仍会二次校验
 */
export const UPLOAD_LIMITS = {
  image: 5 * 1024 * 1024,
  video: 50 * 1024 * 1024,
  file: 20 * 1024 * 1024,
};

/** 上传类型的中文名，用于提示文案 */
export const UPLOAD_TYPE_TEXT = {
  image: '图片',
  video: '视频',
  file: '附件',
};

/** 字节数转可读文本 */
export const formatFileSize = (bytes) => {
  const size = Number(bytes) || 0;
  if (size >= 1024 * 1024) {
    const mb = size / 1024 / 1024;
    return `${Number.isInteger(mb) ? mb : mb.toFixed(1)}MB`;
  }
  return `${Math.max(1, Math.round(size / 1024))}KB`;
};
