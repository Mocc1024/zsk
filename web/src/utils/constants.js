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

/** 管理员角色 */
export const ROLE_OPTIONS = [
  { label: '超级管理员', value: 'admin' },
  { label: '编辑', value: 'editor' },
];

export const roleLabel = (value) =>
  ROLE_OPTIONS.find((item) => item.value === value)?.label ?? value;

/** 通用启用状态 */
export const ENABLE_OPTIONS = [
  { label: '启用', value: 1 },
  { label: '禁用', value: 0 },
];
