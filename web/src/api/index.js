import request from './request';

/** 认证 */
export const authApi = {
  login: (data) => request.post('/auth/login', data),
  profile: () => request.get('/auth/profile'),
  logout: () => request.post('/auth/logout'),
  password: (data) => request.post('/auth/password', data),
};

/** 数据看板 */
export const dashboardApi = {
  index: () => request.get('/dashboard'),
};

/** 开放平台（应用管理，M3.1） */
export const openAppApi = {
  list: (params) => request.get('/open-app', { params }),
  detail: (id) => request.get(`/open-app/${id}`),
  create: (data) => request.post('/open-app', data),
  update: (id, data) => request.put(`/open-app/${id}`, data),
  setStatus: (id, status) => request.put(`/open-app/${id}/status`, { status }),
  rotate: (id) => request.post(`/open-app/${id}/rotate`),
};

/** C 端用户管理（M3.7） */
export const userManageApi = {
  list: (params) => request.get('/user-manage', { params }),
  create: (data) => request.post('/user-manage', data),
  detail: (id) => request.get(`/user-manage/${id}`),
  update: (id, data) => request.put(`/user-manage/${id}`, data),
  setStatus: (id, status) => request.put(`/user-manage/${id}/status`, { status }),
  resetPassword: (id) => request.post(`/user-manage/${id}/reset-password`),
  points: (id, data) => request.post(`/user-manage/${id}/points`, data),
  exp: (id, data) => request.post(`/user-manage/${id}/exp`, data),
  restore: (id) => request.post(`/user-manage/${id}/restore`),
};

/** 等级规则（M3.7） */
export const levelApi = {
  list: () => request.get('/level'),
  create: (data) => request.post('/level', data),
  update: (id, data) => request.put(`/level/${id}`, data),
  recalculate: () => request.post('/level/recalculate'),
};

/** 评论审核与配置（M3.7） */
export const commentAuditApi = {
  list: (params) => request.get('/comment-audit', { params }),
  audit: (id, result, note) => request.put(`/comment-audit/${id}/audit`, { result, handle_result: note }),
  remove: (id) => request.delete(`/comment-audit/${id}`),
  config: () => request.get('/comment-audit/config'),
  saveConfig: (data) => request.put('/comment-audit/config', data),
};

/** 敏感词库（M3.7） */
export const sensitiveApi = {
  list: (params) => request.get('/sensitive-word', { params }),
  create: (data) => request.post('/sensitive-word', data),
  remove: (id) => request.delete(`/sensitive-word/${id}`),
};

/** 举报处理（M3.7） */
export const reportApi = {
  list: (params) => request.get('/report', { params }),
  handle: (id, data) => request.put(`/report/${id}/handle`, data),
};

/** 积分规则与统计（M3.7） */
export const pointConfigApi = {
  rules: () => request.get('/points/rule'),
  saveRules: (data) => request.post('/points/rule', data),
  stat: () => request.get('/points/stat'),
};

/** 内容管理 */
export const contentApi = {
  list: (params) => request.get('/content', { params }),
  detail: (id) => request.get(`/content/${id}`),
  create: (data) => request.post('/content', data),
  update: (id, data) => request.put(`/content/${id}`, data),
  remove: (id) => request.delete(`/content/${id}`),
  batchDelete: (ids) => request.post('/content/batch-delete', { ids }),
  setStatus: (id, status) => request.put(`/content/${id}/status`, { status }),
  setTop: (id, isTop) => request.put(`/content/${id}/top`, { is_top: isTop ? 1 : 0 }),
  // 自动保存（M2.4）
  autosave: (data) => request.post('/content/autosave', data),
  // 回收站（M2.3）
  trash: (params) => request.get('/content/trash', { params }),
  trashDetail: (id) => request.get(`/content/trash/${id}`),
  restore: (id) => request.post(`/content/${id}/restore`),
  forceDelete: (id) => request.post(`/content/${id}/force-delete`),
  batchRestore: (ids) => request.post('/content/batch-restore', { ids }),
  batchForceDelete: (ids) => request.post('/content/batch-force-delete', { ids }),
  // 复制（M2.5）
  copy: (id, data) => request.post(`/content/${id}/copy`, data),
  // 导出（M2.6）
  exportFile: (data) => request.post('/content/export', data),
};

/** 文件上传 */
export const uploadApi = {
  image: (formData) => request.post('/upload/image', formData, { timeout: 120000 }),
  video: (formData) => request.post('/upload/video', formData, { timeout: 120000 }),
  file: (formData) => request.post('/upload/file', formData, { timeout: 120000 }),
};

/** 素材库 */
export const attachmentApi = {
  list: (params) => request.get('/attachment', { params }),
  folders: () => request.get('/attachment/folders'),
  update: (id, data) => request.put(`/attachment/${id}`, data),
  remove: (id) => request.delete(`/attachment/${id}`),
  batchDelete: (ids) => request.post('/attachment/batch-delete', { ids }),
  move: (ids, folder) => request.put('/attachment/move', { ids, folder }),
};

/** 素材分组 */
export const folderApi = {
  create: (data) => request.post('/attachment/folders', data),
  update: (id, data) => request.put(`/attachment/folders/${id}`, data),
  remove: (id) => request.delete(`/attachment/folders/${id}`),
};

/** 标签管理 */
export const tagApi = {
  list: (params) => request.get('/tag', { params }),
  options: () => request.get('/tag/options'),
  create: (data) => request.post('/tag', data),
  update: (id, data) => request.put(`/tag/${id}`, data),
  remove: (id) => request.delete(`/tag/${id}`),
};

/** 分类管理 */
export const categoryApi = {
  list: (params) => request.get('/category', { params }),
  // params 可选：传 { include_disabled: 1 } 时连同禁用分类一起返回（编辑内容用），
  // 不传则保持原行为——只返回启用分类（列表筛选用）
  options: (params) => request.get('/category/options', { params }),
  detail: (id) => request.get(`/category/${id}`),
  create: (data) => request.post('/category', data),
  update: (id, data) => request.put(`/category/${id}`, data),
  remove: (id) => request.delete(`/category/${id}`),
  setStatus: (id, status) => request.put(`/category/${id}/status`, { status }),
};

/** 管理员管理 */
export const adminApi = {
  list: (params) => request.get('/admin', { params }),
  create: (data) => request.post('/admin', data),
  update: (id, data) => request.put(`/admin/${id}`, data),
  remove: (id) => request.delete(`/admin/${id}`),
  setStatus: (id, status) => request.put(`/admin/${id}/status`, { status }),
};

/** 权限菜单 */
export const ruleApi = {
  tree: (params) => request.get('/permission/tree', { params }),
  create: (data) => request.post('/permission', data),
  update: (id, data) => request.put(`/permission/${id}`, data),
  remove: (id) => request.delete(`/permission/${id}`),
  setStatus: (id, status) => request.put(`/permission/${id}/status`, { status }),
};

/** 用户组（角色） */
export const groupApi = {
  list: (params) => request.get('/group', { params }),
  options: () => request.get('/group/options'),
  create: (data) => request.post('/group', data),
  update: (id, data) => request.put(`/group/${id}`, data),
  remove: (id) => request.delete(`/group/${id}`),
  setStatus: (id, status) => request.put(`/group/${id}/status`, { status }),
  rules: (id) => request.get(`/group/${id}/rules`),
  authorize: (id, rules) => request.put(`/group/${id}/rules`, { rules }),
};

/** 操作日志 */
export const logApi = {
  list: (params) => request.get('/log', { params }),
  clear: () => request.delete('/log/clear'),
};
