import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { authApi } from '../api';

const AuthContext = createContext(null);

/** 读取本地缓存，解析失败时返回兜底值，避免脏数据导致白屏 */
function readCache(key, fallback) {
  try {
    const cached = localStorage.getItem(key);
    return cached ? JSON.parse(cached) : fallback;
  } catch (e) {
    return fallback;
  }
}

export function AuthProvider({ children }) {
  const [admin, setAdmin] = useState(() => readCache('zsk_admin', null));
  // 同步从本地恢复：刷新后首次渲染就有权限，避免操作列/菜单先空后闪
  const [permissions, setPermissions] = useState(() => readCache('zsk_permissions', []));
  const [menus, setMenus] = useState(() => readCache('zsk_menus', []));
  const [loading, setLoading] = useState(false);

  const token = localStorage.getItem('zsk_token');

  const setSession = useCallback((nextToken, nextAdmin, nextPermissions, nextMenus) => {
    // 登录即全新会话：丢弃上一个账号 / 上一次残留的菜单展开状态，
    // 否则换账号登录后会把前任展开过的分组原样继承下来
    localStorage.removeItem('zsk_menu_open_keys');

    if (nextToken) {
      localStorage.setItem('zsk_token', nextToken);
    }
    if (nextAdmin) {
      localStorage.setItem('zsk_admin', JSON.stringify(nextAdmin));
      setAdmin(nextAdmin);
    }
    if (Array.isArray(nextPermissions)) {
      localStorage.setItem('zsk_permissions', JSON.stringify(nextPermissions));
      setPermissions(nextPermissions);
    }
    if (Array.isArray(nextMenus)) {
      localStorage.setItem('zsk_menus', JSON.stringify(nextMenus));
      setMenus(nextMenus);
    }
  }, []);

  const logout = useCallback(() => {
    localStorage.removeItem('zsk_token');
    localStorage.removeItem('zsk_admin');
    localStorage.removeItem('zsk_permissions');
    localStorage.removeItem('zsk_menus');
    localStorage.removeItem('zsk_menu_open_keys');
    setAdmin(null);
    setPermissions([]);
    setMenus([]);
  }, []);

  // 已有令牌时拉取一次最新权限（仅一次，避免死循环）
  const fetchedRef = useRef(false);
  useEffect(() => {
    if (!token || fetchedRef.current) {
      return;
    }
    fetchedRef.current = true;
    setLoading(true);
    authApi
      .profile()
      .then((res) => {
        localStorage.setItem('zsk_admin', JSON.stringify(res.data.admin));
        localStorage.setItem('zsk_permissions', JSON.stringify(res.data.permissions || []));
        localStorage.setItem('zsk_menus', JSON.stringify(res.data.menus || []));
        setAdmin(res.data.admin);
        setPermissions(res.data.permissions || []);
        setMenus(res.data.menus || []);
      })
      .catch(() => logout())
      .finally(() => setLoading(false));
  }, [token, admin, permissions, menus, logout]);

  /**
   * 是否拥有某权限
   * 后端对超级管理员返回 ['*']，此处直接放行
   */
  const hasPermission = useCallback(
    (key) => permissions.includes('*') || permissions.includes(key),
    [permissions]
  );

  const value = useMemo(
    () => ({
      admin,
      token,
      loading,
      permissions,
      menus,
      // 超级管理员的 permissions 为 ['*']，由用户组的 rules='*' 决定
      isSuper: permissions.includes('*'),
      hasPermission,
      setSession,
      logout,
    }),
    [admin, token, loading, permissions, menus, hasPermission, setSession, logout]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth 必须在 AuthProvider 内使用');
  }
  return ctx;
}
