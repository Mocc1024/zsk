import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// 后端接口地址（开发环境下由 vite 代理转发）
const API_TARGET = 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  // 线上部署在 www.zsk.com/admin/
  base: '/admin/',
  build: {
    outDir: '../public/admin',
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
    },
  },
});
