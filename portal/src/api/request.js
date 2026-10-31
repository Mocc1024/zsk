import axios from 'axios';

// 前台只调 /api/front/*（免鉴权只读）与 /api/front/auth/*（登录态接口）
// 第三方走的是 /api/open/*，需要 AppKey 签名，前台不要混用
const request = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
  timeout: 20000,
});

// 令牌存储键与后台的 zsk_token 区分开：
// 两者同域部署，用同一个键会出现「登录前台把后台踢下线」
const TOKEN_KEY = 'zsk_user_token';
const REFRESH_KEY = 'zsk_user_refresh';
const USER_KEY = 'zsk_user';

export function getUserToken() {
  return localStorage.getItem(TOKEN_KEY) || '';
}

export function getRefreshToken() {
  return localStorage.getItem(REFRESH_KEY) || '';
}

export function setUserToken(token, refreshToken, user) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_KEY);
  }
  if (refreshToken) {
    localStorage.setItem(REFRESH_KEY, refreshToken);
  } else if (refreshToken === '') {
    localStorage.removeItem(REFRESH_KEY);
  }
  if (user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  } else {
    localStorage.removeItem(USER_KEY);
  }
}

export function getStoredUser() {
  const raw = localStorage.getItem(USER_KEY);
  if (!raw) {
    return null;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

/** 正在续期的 Promise：多个请求同时 401 时只续一次，避免并发重复刷新 */
let refreshing = null;

/**
 * 用 Refresh Token 换一张新 Access Token
 * 失败返回 null，由调用方清理登录态
 */
async function doRefresh() {
  const refreshToken = getRefreshToken();
  if (!refreshToken) {
    return null;
  }
  try {
    const res = await axios.post(
      `${import.meta.env.VITE_API_BASE || '/api'}/front/auth/refresh`,
      { refresh_token: refreshToken },
      { headers: { Accept: 'application/json' } }
    );
    const body = res.data || {};
    if (body.code !== 0 || !body.data?.token) {
      return null;
    }
    setUserToken(body.data.token, undefined, undefined);
    return body.data.token;
  } catch {
    return null;
  }
}

request.interceptors.request.use((config) => {
  const token = getUserToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

request.interceptors.response.use(
  (response) => {
    const res = response.data || {};
    if (res.code === 0) {
      return res;
    }
    return Promise.reject(Object.assign(new Error(res.msg || '请求失败'), { code: res.code }));
  },
  async (error) => {
    const status = error?.response?.status;
    const res = error?.response?.data || {};
    const original = error?.config || {};

    if ((status === 401 || res.code === 401) && !original.__retried) {
      original.__retried = true;

      // 只重试一次：__retried 标记防止 Refresh 本身也 401 时死循环
      refreshing = refreshing || doRefresh();
      const next = await refreshing;
      refreshing = null;

      if (next) {
        original.headers = { ...(original.headers || {}), Authorization: `Bearer ${next}` };
        return request(original);
      }

      setUserToken('', '', null);
      return Promise.reject(error);
    }

    return Promise.reject(Object.assign(new Error(res.msg || error.message || '网络异常'), { code: res.code ?? status }));
  }
);

export default request;
