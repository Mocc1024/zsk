import request from './request';

/**
 * 前台内容接口（免鉴权只读，M3.2 实现）
 * 与 /api/open/* 的区别见 ADR-011：前者给自家 SPA 用，后者给第三方签名调用。
 */
export const frontApi = {
  home: () => request.get('/front/home'),
  contents: (params) => request.get('/front/contents', { params }),
  content: (id) => request.get(`/front/content/${id}`),
  search: (params) => request.get('/front/search', { params }),
  // 导航栏的分类列表：BasicLayout 全局取一次。
  // 这里曾是漏网之鱼——调用方写在 useEffect 里，构建能过、curl 也全 200，
  // 只有真开浏览器才能看到 TypeError: frontApi.categories is not a function，
  // 然后整棵 React 树崩溃白屏。
  categories: () => request.get('/front/categories'),
  statView: (data) => request.post('/front/stat/view', data),
};

/** 收藏（M3.4） */
export const favoriteApi = {
  list: (params) => request.get('/front/favorites', { params }),
  check: (contentId) => request.get('/front/favorite/check', { params: { content_id: contentId } }),
  add: (contentId) => request.post('/front/favorite', { content_id: contentId }),
  remove: (contentId) => request.delete(`/front/favorite/${contentId}`),
};

/** 评论 / 点赞 / 举报（M3.5） */
export const commentApi = {
  list: (contentId, params = {}) =>
    request.get('/front/comments', { params: { content_id: contentId, ...params } }),
  create: (data) => request.post('/front/comment', data),
  like: (id) => request.post(`/front/comment/${id}/like`),
  unlike: (id) => request.delete(`/front/comment/${id}/like`),
  report: (data) => request.post('/front/report', data),
};

/** 签到 / 积分 / 等级（M3.6） */
export const pointApi = {
  signin: () => request.post('/front/signin'),
  signinStatus: () => request.get('/front/signin/status'),
  points: (params) => request.get('/front/points', { params }),
  exp: (params) => request.get('/front/exp', { params }),
  levels: () => request.get('/front/levels'),
};

/**
 * C 端用户接口（M3.3）
 * ping 用于令牌探活，captcha/login/register 免鉴权，其余需登录。
 */
export const userApi = {
  ping: () => request.get('/front/ping'),
  captcha: () => request.post('/front/auth/captcha'),
  register: (data) => request.post('/front/auth/register', data),
  login: (data) => request.post('/front/auth/login', data),
  logout: (data) => request.post('/front/auth/logout', data),
  profile: () => request.get('/front/user/profile'),
  updateProfile: (data) => request.put('/front/user/profile', data),
  password: (data) => request.post('/front/user/password', data),
  deactivate: () => request.post('/front/user/deactivate'),
};
