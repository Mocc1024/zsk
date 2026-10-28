import axios from 'axios';
import { message } from 'antd';

// 默认同源 /api（部署在 www.zsk.com/admin/ 时直接走 Nginx + PHP）
// 如需临时指向其它地址：构建时设置 VITE_API_BASE=http://127.0.0.1:8000/api
const request = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
  timeout: 20000,
});

// 请求拦截：自动携带令牌
request.interceptors.request.use((config) => {
  const token = localStorage.getItem('zsk_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// 响应拦截：统一拆包与错误处理
request.interceptors.response.use(
  (response) => {
    const res = response.data || {};
    if (res.code === 0) {
      return res;
    }
    message.error(res.msg || '请求失败');
    return Promise.reject(new Error(res.msg || '请求失败'));
  },
  (error) => {
    const status = error?.response?.status;
    const res = error?.response?.data || {};

    if (status === 401 || res.code === 401) {
      localStorage.removeItem('zsk_token');
      localStorage.removeItem('zsk_admin');
      if (!window.location.hash.includes('/login')) {
        message.warning('登录状态已失效，请重新登录');
        window.location.href = '#/login';
        window.location.reload();
      }
      return Promise.reject(error);
    }

    message.error(res.msg || error.message || '网络异常，请稍后重试');
    return Promise.reject(error);
  }
);

export default request;
