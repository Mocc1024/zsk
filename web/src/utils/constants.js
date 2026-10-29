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
