import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// 后端接口地址（开发环境下由 vite 代理转发）
const API_TARGET = 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  // 线上部署在 www.zsk.com/zsk-cms-7c4e/
  // 不用 /admin：扫描器的字典里 admin 排在最前面，改个名字能挡掉绝大多数无差别扫描。
  // 注意这只是减少噪音，真正的门在登录认证与权限，不靠路径保密。
  base: '/zsk-cms-7c4e/',
  build: {
    outDir: '../public/zsk-cms-7c4e',
    emptyOutDir: true,
    chunkSizeWarningLimit: 1500,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: API_TARGET,
        changeOrigin: true,
      },
      // 素材库上传的图片直接由 PHP 服务提供，开发环境同样转发
      '/storage': {
        target: API_TARGET,
        changeOrigin: true,
      },
    },
  },
});
