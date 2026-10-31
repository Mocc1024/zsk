import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import BasicLayout from './layout/BasicLayout';
import Home from './pages/Home';
import ContentList from './pages/ContentList';
import ContentDetail from './pages/ContentDetail';
import SearchPage from './pages/SearchPage';
import Login from './pages/Login';
import Profile from './pages/Profile';
import Favorites from './pages/Favorites';
import NotFound from './pages/NotFound';
import { UserAuthProvider, useUserAuth } from './store/UserAuthContext';

/**
 * 需要登录才能访问的路由
 *
 * 这只是前端的体验拦截（避免闪一下再跳登录），
 * 真正的权限判定始终在服务端 UserAuth —— 前端拦不拦都绕不过 401。
 */
function RequireUser({ children }) {
  const { isLogin, loading } = useUserAuth();

  if (loading) {
    return <div className="card placeholder">加载中…</div>;
  }
  if (!isLogin) {
    return <Navigate to="/login" replace />;
  }
  return children;
}

function AppRoutes() {
  return (
    <Routes>
      <Route element={<BasicLayout />}>
        <Route path="/" element={<Home />} />
        {/* 分类与标签共用 ContentList，由 mode 决定过滤条件 */}
        <Route path="/category/:id" element={<ContentList mode="category" />} />
        <Route path="/tag/:id" element={<ContentList mode="tag" />} />
        <Route path="/content/:id" element={<ContentDetail />} />
        <Route path="/search" element={<SearchPage />} />
        <Route path="/login" element={<Login />} />
        <Route
          path="/profile"
          element={
            <RequireUser>
              <Profile />
            </RequireUser>
          }
        />
        <Route
          path="/favorites"
          element={
            <RequireUser>
              <Favorites />
            </RequireUser>
          }
        />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  );
}

/**
 * 前台路由
 *
 * 用 BrowserRouter 而不是后台那种 HashRouter：3.5 的 SEO 与 Sitemap 要求
 * URL 是真实路径，带 # 的哈希路由搜索引擎抓不到。
 * 代价：线上需要服务端把所有未知路径回退到 index.html（见部署说明）。
 */
export default function App() {
  return (
    <BrowserRouter>
      <UserAuthProvider>
        <AppRoutes />
      </UserAuthProvider>
    </BrowserRouter>
  );
}
