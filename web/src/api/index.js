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
};

/** 分类管理 */
export const categoryApi = {
  list: (params) => request.get('/category', { params }),
  options: () => request.get('/category/options'),
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
  permissions: (id) => request.get(`/admin/${id}/permissions`),
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
