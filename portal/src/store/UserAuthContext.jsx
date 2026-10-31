import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { getRefreshToken, getStoredUser, getUserToken, setUserToken } from '../api/request';
import { userApi } from '../api';

const UserAuthContext = createContext(null);

/**
 * 全局用户态
 *
 * 冷启动时不直接信任 localStorage：令牌可能已经过期或被拉黑，
 * 先拿服务端探一次，探不通就清掉本地凭据。
 */
export function UserAuthProvider({ children }) {
  const [token, setToken] = useState(getUserToken());
  const [user, setUser] = useState(getStoredUser());
  const [loading, setLoading] = useState(() => !!getUserToken());

  useEffect(() => {
    if (!token) {
      setLoading(false);
      return;
    }
    let alive = true;
    userApi
      .profile()
      .then((res) => alive && setUser(res.data.user || null))
      .catch(() => alive && applySession('', '', null))
      .finally(() => alive && setLoading(false));

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const applySession = useCallback((nextToken, nextRefresh, nextUser) => {
    setUserToken(nextToken, nextRefresh, nextUser);
    setToken(nextToken || '');
    setUser(nextUser || null);
  }, []);

  const login = useCallback(
    async (payload) => {
      const res = await userApi.login(payload);
      applySession(res.data.token, res.data.refreshToken, res.data.user);
      return res.data;
    },
    [applySession]
  );

  const register = useCallback(
    async (payload) => {
      const res = await userApi.register(payload);
      applySession(res.data.token, res.data.refreshToken, res.data.user);
      return res.data;
    },
    [applySession]
  );

  const logout = useCallback(async () => {
    try {
      await userApi.logout({ refresh_token: getRefreshToken() });
    } catch {
      // 服务端失效也要把本地清干净，不能因为接口报错就退不出去
    }
    applySession('', '', null);
  }, [applySession]);

  const value = useMemo(
    () => ({ token, user, loading, isLogin: !!token && !!user, login, register, logout, setUser }),
    [token, user, loading, login, register, logout]
  );

  return <UserAuthContext.Provider value={value}>{children}</UserAuthContext.Provider>;
}

export function useUserAuth() {
  const ctx = useContext(UserAuthContext);
  if (!ctx) {
    throw new Error('useUserAuth 必须在 UserAuthProvider 内使用');
  }
  return ctx;
}
