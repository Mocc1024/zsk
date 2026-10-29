import { useMemo, useState } from 'react';
import { useLocation, useNavigate, Outlet } from 'react-router-dom';
import {
  Avatar,
  Button,
  Dropdown,
  Form,
  Input,
  Layout,
  Menu,
  Modal,
  Tag,
  Typography,
  message,
} from 'antd';
import {
  AppstoreOutlined,
  DashboardOutlined,
  FileTextOutlined,
  HistoryOutlined,
  LockOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { useAuth } from '../store/AuthContext';
import { authApi } from '../api';

const { Header, Sider, Content } = Layout;

/** 后端返回图标名 → 前端图标组件 */
const ICONS = {
  AppstoreOutlined: <AppstoreOutlined />,
  DashboardOutlined: <DashboardOutlined />,
  FileTextOutlined: <FileTextOutlined />,
  HistoryOutlined: <HistoryOutlined />,
  MenuOutlined: <MenuOutlined />,
  SafetyCertificateOutlined: <SafetyCertificateOutlined />,
  SettingOutlined: <SettingOutlined />,
  TeamOutlined: <TeamOutlined />,
  UserOutlined: <UserOutlined />,
};

/** 把后端菜单树转成 antd Menu items */
const buildMenu = (nodes = []) =>
  nodes.map((node) => ({
    key: node.path || `k-${node.id}`,
    icon: ICONS[node.icon] || <AppstoreOutlined />,
    label: node.title,
    children: node.children?.length ? buildMenu(node.children) : undefined,
  }));

export default function BasicLayout() {
  const { admin, isSuper, hasPermission, menus, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [collapsed, setCollapsed] = useState(false);
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [form] = Form.useForm();

  // 菜单完全由后端下发（已按用户组权限过滤），前端只做渲染
  const menuItems = useMemo(() => buildMenu(menus), [menus]);

  // 顶栏展示当前用户所属的用户组
  const groupText = (admin?.group_names || []).join('、') || '未分配';

  const selectedKey = useMemo(() => {
    const seg = location.pathname.split('/').filter(Boolean)[0] || 'dashboard';
    return `/${seg}`;
  }, [location.pathname]);

  const handleLogout = async () => {
    try {
      await authApi.logout();
    } catch (e) {
      // 忽略退出接口异常
    }
    logout();
    message.success('已安全退出');
    navigate('/login', { replace: true });
  };

  const handleSubmitPassword = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      await authApi.password(values);
      message.success('密码修改成功，请重新登录');
      logout();
      navigate('/login', { replace: true });
    } catch (e) {
      // 错误已在拦截器中提示
    } finally {
      setSubmitting(false);
    }
  };

  const userMenu = {
    items: [
      { key: 'group', label: `用户组：${groupText}`, disabled: true },
      { type: 'divider' },
      { key: 'password', icon: <LockOutlined />, label: '修改密码' },
      { key: 'logout', icon: <LogoutOutlined />, label: '退出登录' },
    ],
    onClick: ({ key }) => {
      if (key === 'password') {
        form.resetFields();
        setPasswordOpen(true);
      }
      if (key === 'logout') {
        handleLogout();
      }
    },
  };

  return (
    <Layout className="app-layout">
      <Sider
        className="app-sider"
        collapsible
        collapsed={collapsed}
        trigger={null}
        theme="dark"
        width={216}
      >
        <div className="app-logo">
          <AppstoreOutlined />
          {!collapsed && <span>ZSK 管理后台</span>}
        </div>
        <Menu
          theme="dark"
          mode="inline"
          selectedKeys={[selectedKey]}
          items={menuItems}
          onClick={({ key }) => navigate(key)}
        />
      </Sider>
      <Layout className="app-main">
        <Header className="app-header">
          <Button
            type="text"
            icon={collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
            onClick={() => setCollapsed(!collapsed)}
            style={{ fontSize: 16, width: 48, height: 48 }}
          />
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <Tag color={isSuper ? 'blue' : 'green'}>{groupText}</Tag>
            <Dropdown menu={userMenu} placement="bottomRight">
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                <Avatar size="small" icon={<UserOutlined />} style={{ backgroundColor: '#2f54eb' }} />
                <span>{admin?.nickname || admin?.username}</span>
              </div>
            </Dropdown>
          </div>
        </Header>
        <Content className="app-content">
          <Outlet />
        </Content>
      </Layout>

      <Modal
        title="修改登录密码"
        open={passwordOpen}
        onCancel={() => setPasswordOpen(false)}
        onOk={handleSubmitPassword}
        confirmLoading={submitting}
        okText="确认修改"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item
            name="old_password"
            label="原密码"
            rules={[{ required: true, message: '请输入原密码' }]}
          >
            <Input.Password placeholder="请输入当前使用的密码" />
          </Form.Item>
          <Form.Item
            name="new_password"
            label="新密码"
            rules={[
              { required: true, message: '请输入新密码' },
              { min: 6, message: '新密码至少 6 位' },
            ]}
          >
            <Input.Password placeholder="请输入新密码（至少 6 位）" />
          </Form.Item>
          <Form.Item
            name="confirm_password"
            label="确认新密码"
            dependencies={['new_password']}
            rules={[
              { required: true, message: '请再次输入新密码' },
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (!value || getFieldValue('new_password') === value) {
                    return Promise.resolve();
                  }
                  return Promise.reject(new Error('两次输入的密码不一致'));
                },
              }),
            ]}
          >
            <Input.Password placeholder="请再次输入新密码" />
          </Form.Item>
        </Form>
      </Modal>
    </Layout>
  );
}
