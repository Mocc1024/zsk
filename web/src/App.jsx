import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Spin } from 'antd';
import { AuthProvider, useAuth } from './store/AuthContext';
import BasicLayout from './layout/BasicLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import ContentList from './pages/ContentList';
import ContentEdit from './pages/ContentEdit';
import ContentTrash from './pages/ContentTrash';
import AttachmentList from './pages/AttachmentList';
import TagList from './pages/TagList';
import CategoryList from './pages/CategoryList';
import AdminList from './pages/AdminList';
import GroupList from './pages/GroupList';
import PermissionList from './pages/PermissionList';
import LogList from './pages/LogList';

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
      </Route>
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <HashRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </HashRouter>
  );
}
