import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, Outlet } from 'react-router-dom';
import { Avatar, Button, Dropdown, Layout, Menu, Tag, Typography, message } from 'antd';
import {
  AppstoreOutlined,
  DashboardOutlined,
  DeleteOutlined,
  FileTextOutlined,
  HistoryOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuOutlined,
  MenuUnfoldOutlined,
  PictureOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  TagsOutlined,
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
  DeleteOutlined: <DeleteOutlined />,
  FileTextOutlined: <FileTextOutlined />,
  HistoryOutlined: <HistoryOutlined />,
  MenuOutlined: <MenuOutlined />,
  PictureOutlined: <PictureOutlined />,
  SafetyCertificateOutlined: <SafetyCertificateOutlined />,
  SettingOutlined: <SettingOutlined />,
  TagsOutlined: <TagsOutlined />,
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

/** 找出 key 所在的所有父级 key（不含自身），用于自动展开子菜单所在分支 */
const findParentKeys = (items, key, trail = []) => {
  for (const item of items) {
    if (item.key === key) {
      return trail;
    }
    if (item.children?.length) {
      const found = findParentKeys(item.children, key, [...trail, item.key]);
      if (found) {
        return found;
      }
    }
  }
  return null;
};

/** 收集所有「含子菜单」的容器 key，用于剔除缓存里已失效的展开项 */
const collectParentKeys = (items) => {
  const keys = [];
  const walk = (nodes) => {
    nodes.forEach((node) => {
      if (node.children?.length) {
        keys.push(node.key);
        walk(node.children);
      }
    });
  };
  walk(items);
  return keys;
};

/** 读取展开状态的本地缓存，解析失败时返回空数组 */
const readOpenKeys = () => {
  try {
    const cached = localStorage.getItem('zsk_menu_open_keys');
    const parsed = cached ? JSON.parse(cached) : [];
    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    return [];
  }
};

export default function BasicLayout() {
  const { admin, isSuper, hasPermission, menus, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [collapsed, setCollapsed] = useState(false);
  // 展开状态受控：会话内持久化（刷新仍保持用户手动展开的分支），
  // 登录时已在 AuthContext.setSession 中清空，因此每次登录都从全部收起开始
  const [openKeys, setOpenKeys] = useState(readOpenKeys);

  // 菜单完全由后端下发（已按用户组权限过滤），前端只做渲染
  const menuItems = useMemo(() => buildMenu(menus), [menus]);

  // 顶栏展示当前用户所属的用户组
  const groupText = (admin?.group_names || []).join('、') || '未分配';

  // 取当前路径下匹配最长的菜单项，保证二级菜单（如 /content/trash）也能正确高亮
  const selectedKey = useMemo(() => {
    const seg = location.pathname.split('/').filter(Boolean)[0] || 'dashboard';
    let best = `/${seg}`;

    const walk = (items) => {
      items.forEach((item) => {
        if (
          typeof item.key === 'string'
          && item.key.startsWith('/')
          && location.pathname.startsWith(item.key)
          && item.key.length > best.length
        ) {
          best = item.key;
        }
        if (item.children) {
          walk(item.children);
        }
      });
    };
    walk(menuItems);

    return best;
  }, [location.pathname, menuItems]);

  // 当前路由对应的菜单项可能位于二级（如 内容回收站），
  // 其父级必须展开才能看到高亮；菜单数据是异步下发的，数据到位后要补展开
  useEffect(() => {
    if (!menuItems.length) {
      return;
    }

    // 只保留仍是「容器」的 key：菜单结构变动后，缓存里的旧 key 不再有效
    const validKeys = collectParentKeys(menuItems);
    const parents = findParentKeys(menuItems, selectedKey) || [];

    setOpenKeys((prev) => {
      const kept = prev.filter((key) => validKeys.includes(key));
      const missing = parents.filter((key) => !kept.includes(key));
      if (kept.length === prev.length && !missing.length) {
        return prev;
      }
      return [...kept, ...missing];
    });
  }, [menuItems, selectedKey]);

  const handleOpenChange = (keys) => {
    setOpenKeys(keys);
    localStorage.setItem('zsk_menu_open_keys', JSON.stringify(keys));
  };

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

  const userMenu = {
    items: [
      { key: 'group', label: `用户组：${groupText}`, disabled: true },
      { type: 'divider' },
      { key: 'profile', icon: <UserOutlined />, label: '个人中心' },
      { key: 'security', icon: <SafetyCertificateOutlined />, label: '安全设置' },
      { key: 'logout', icon: <LogoutOutlined />, label: '退出登录' },
    ],
    onClick: ({ key }) => {
      if (key === 'profile') {
        navigate('/profile');
      }
      if (key === 'security') {
        navigate('/security');
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
          openKeys={openKeys}
          onOpenChange={handleOpenChange}
          items={menuItems}
          onClick={({ key }) => {
            // 纯容器菜单（如 内容管理 / 系统管理）没有 path，key 会退化成 k-<id>，
            // 点击只应展开子菜单，不能当成路由跳转
            if (typeof key === 'string' && key.startsWith('k-')) {
              return;
            }
            navigate(key);
          }}
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

    </Layout>
  );
}
