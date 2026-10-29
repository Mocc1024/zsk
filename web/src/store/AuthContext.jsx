import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { authApi } from '../api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [admin, setAdmin] = useState(() => {
    const cached = localStorage.getItem('zsk_admin');
    return cached ? JSON.parse(cached) : null;
  });
  const [permissions, setPermissions] = useState([]);
  const [menus, setMenus] = useState([]);
  const [loading, setLoading] = useState(false);

  const token = localStorage.getItem('zsk_token');

  const setSession = useCallback((nextToken, nextAdmin, nextPermissions, nextMenus) => {
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
    setAdmin(null);
    setPermissions([]);
    setMenus([]);
  }, []);

  // 刷新后先从本地恢复权限与菜单，避免菜单闪烁
  useEffect(() => {
    const cached = localStorage.getItem('zsk_permissions');
    if (cached) {
      setPermissions(JSON.parse(cached));
    }
    const cachedMenus = localStorage.getItem('zsk_menus');
    if (cachedMenus) {
      setMenus(JSON.parse(cachedMenus));
    }
  }, []);

  // 已有令牌但用户信息/权限缺失时，拉取一次（仅一次，避免死循环）
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
