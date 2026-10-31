import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// 后端接口地址（开发环境下由 vite 代理转发）
const API_TARGET = 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  // 部署路径。线上前台占站点根（www.zsk.com/），所以默认是 '/'。
  //
  // 但产物输出到 public/portal，本地想直接开 http://127.0.0.1:8000/portal/ 预览时，
  // index.html 里就必须引用 /portal/assets/... —— 用 '/' 的话资源全 404，
  // 页面能打开但一片空白（HTML 200、JS 404，很容易误判成后端坏了）。
  // 故允许用环境变量覆盖：
  //   本地预览：VITE_BASE_PATH=/portal/ npm run build
  //   线上部署：npm run build
  base: process.env.VITE_BASE_PATH || '/',
  build: {
    outDir: '../public/portal',
    emptyOutDir: true,
    chunkSizeWarningLimit: 800,
    rollupOptions: {
      output: {
        // 第三方依赖单独成包，避免业务代码一改就让用户重新下载 vendor。
        // 必须用函数形式：'react-dom/client'、'scheduler' 这些子路径
        // 用对象形式 { react: ['react', 'react-dom'] } 匹配不到，会漏进别的包。
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return undefined;
          }
          if (id.includes('react-router') || id.includes('@remix-run')) {
            return 'router';
          }
          if (id.includes('react') || id.includes('scheduler')) {
            return 'react';
          }
          return 'vendor';
        },
      },
    },
  },
  server: {
    host: '0.0.0.0',
    // 后台 web/ 占用 5173，前台顺延一位，两个工程可同时 dev 互不影响
    port: 5174,
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
