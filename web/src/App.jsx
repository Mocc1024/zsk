import { lazy, Suspense } from 'react';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Spin } from 'antd';
import { AuthProvider, useAuth } from './store/AuthContext';
import BasicLayout from './layout/BasicLayout';

// 路由级代码分割（M3.0 顺手修复第二阶段遗留：入口 JS 3.29MB）
// 后台是内部系统，首屏只关心登录页和看板，编辑器（wangEditor）、图表（echarts）、
// 文件管理等重依赖不该在打开登录页时就全部下载。
const Login = lazy(() => import('./pages/Login'));
const Dashboard = lazy(() => import('./pages/Dashboard'));
const ContentList = lazy(() => import('./pages/ContentList'));
const ContentEdit = lazy(() => import('./pages/ContentEdit'));
const ContentTrash = lazy(() => import('./pages/ContentTrash'));
const AttachmentList = lazy(() => import('./pages/AttachmentList'));
const TagList = lazy(() => import('./pages/TagList'));
const CategoryList = lazy(() => import('./pages/CategoryList'));
const AdminList = lazy(() => import('./pages/AdminList'));
const GroupList = lazy(() => import('./pages/GroupList'));
const PermissionList = lazy(() => import('./pages/PermissionList'));
const LogList = lazy(() => import('./pages/LogList'));
const OpenAppList = lazy(() => import('./pages/OpenAppList'));
const UserManageList = lazy(() => import('./pages/UserManageList'));
const InteractManage = lazy(() => import('./pages/InteractManage'));

/** 懒加载页面的统一加载态 */
function PageLoading() {
  return (
    <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 160 }}>
      <Spin size="large" />
    </div>
  );
}

function RequireAuth({ children }) {
  const { admin, token, loading } = useAuth();

  if (!token) {
    return <Navigate to="/login" replace />;
  }
  if (!admin) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 160 }}>
        <Spin spinning={loading} tip="正在加载用户信息" size="large" />
      </div>
    );
  }
  return children;
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="/"
        element={
          <RequireAuth>
            <BasicLayout />
          </RequireAuth>
        }
      >
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard" element={<Dashboard />} />
        <Route path="content" element={<ContentList />} />
        <Route path="content/create" element={<ContentEdit />} />
        <Route path="content/trash" element={<ContentTrash />} />
        <Route path="content/edit/:id" element={<ContentEdit />} />
        <Route path="attachment" element={<AttachmentList />} />
        <Route path="tag" element={<TagList />} />
        <Route path="category" element={<CategoryList />} />
        <Route path="admin" element={<AdminList />} />
        <Route path="group" element={<GroupList />} />
        <Route path="permission" element={<PermissionList />} />
        <Route path="log" element={<LogList />} />
        {/* 路径与后台菜单的 path 一致（开放平台菜单 path = /open-app） */}
        <Route path="open-app" element={<OpenAppList />} />
        {/* 路径与后台菜单的 path 一致：用户管理 path = /user，互动管理 path = /interact */}
        <Route path="user" element={<UserManageList />} />
        <Route path="interact" element={<InteractManage />} />
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <HashRouter>
      <AuthProvider>
        <Suspense fallback={<PageLoading />}>
          <AppRoutes />
        </Suspense>
      </AuthProvider>
    </HashRouter>
  );
}
