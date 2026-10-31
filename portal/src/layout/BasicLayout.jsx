import { useEffect, useRef, useState } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  Bookmark,
  ChevronDown,
  LogOut,
  Menu,
  Moon,
  Search,
  Sun,
  User as UserIcon,
  X,
} from 'lucide-react';
import { frontApi } from '../api';
import { useUserAuth } from '../store/UserAuthContext';

/** 主题：跟随系统，手动切换后记住选择 */
function ThemeToggle() {
  const [theme, setTheme] = useState(
    () => document.documentElement.getAttribute('data-theme') || 'light'
  );

  const toggle = () => {
    const next = theme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('zsk_theme', next);
    setTheme(next);
  };

  return (
    <button
      type="button"
      className="icon-btn"
      onClick={toggle}
      aria-label={theme === 'dark' ? '切换到浅色模式' : '切换到深色模式'}
      title={theme === 'dark' ? '浅色模式' : '深色模式'}
    >
      {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
    </button>
  );
}

/**
 * 前台布局：导航 + 搜索 + 内容区 + 页脚
 * 分类导航由接口下发，每个页面都要可见，所以放在布局里取一次。
 */
export default function BasicLayout() {
  const [keyword, setKeyword] = useState('');
  const [categories, setCategories] = useState([]);
  const [menuOpen, setMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const { isLogin, user, logout } = useUserAuth();
  const userMenuRef = useRef(null);

  useEffect(() => {
    let alive = true;
    frontApi
      .categories()
      .then((res) => alive && setCategories(res.data.list || []))
      .catch(() => {
        // 导航取不到不影响主内容，静默降级为只有「首页」
      });
    return () => {
      alive = false;
    };
  }, []);

  // 路由变化后收起移动端菜单与用户菜单
  useEffect(() => {
    setMenuOpen(false);
    setUserMenuOpen(false);
  }, [location.pathname]);

  // 点击空白处关闭用户菜单
  useEffect(() => {
    if (!userMenuOpen) {
      return undefined;
    }
    const onDocClick = (e) => {
      if (userMenuRef.current && !userMenuRef.current.contains(e.target)) {
        setUserMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [userMenuOpen]);

  const onSearch = (e) => {
    e.preventDefault();
    const q = keyword.trim();
    navigate(q ? `/search?q=${encodeURIComponent(q)}` : '/search');
  };

  const isActive = (path) => location.pathname === path;

  const navLinks = (
    <>
      <Link to="/" className={isActive('/') ? 'nav-link active' : 'nav-link'}>
        首页
      </Link>
      {categories.slice(0, 6).map((cat) => (
        <Link
          key={cat.id}
          to={`/category/${cat.id}`}
          className={isActive(`/category/${cat.id}`) ? 'nav-link active' : 'nav-link'}
        >
          {cat.name}
        </Link>
      ))}
    </>
  );

  return (
    <div>
      <header className="site-header">
        <div className="container">
          <div className="header-inner">
            <button
              type="button"
              className="icon-btn nav-toggle"
              onClick={() => setMenuOpen((v) => !v)}
              aria-label="展开导航"
            >
              {menuOpen ? <X size={18} /> : <Menu size={18} />}
            </button>

            <Link to="/" className="brand">
              <span className="brand-mark">Z</span>
              <span>ZSK 知识库</span>
            </Link>

            <nav className="nav">{navLinks}</nav>

            <form className="header-search" onSubmit={onSearch}>
              <input
                value={keyword}
                onChange={(e) => setKeyword(e.target.value)}
                placeholder="搜索内容、标签"
                aria-label="搜索"
              />
            </form>

            <div className="header-actions">
              <Link to="/search" className="icon-btn" aria-label="搜索" title="搜索">
                <Search size={18} />
              </Link>
              <ThemeToggle />

              {isLogin ? (
                <div className="user-menu" ref={userMenuRef}>
                  <button
                    type="button"
                    className="avatar"
                    onClick={() => setUserMenuOpen((v) => !v)}
                    aria-label="用户菜单"
                    aria-expanded={userMenuOpen}
                  >
                    {user?.avatar ? (
                      <img src={user.avatar} alt={user?.nickname || user?.username} />
                    ) : (
                      String(user?.nickname || user?.username || 'U').slice(0, 1).toUpperCase()
                    )}
                  </button>

                  {userMenuOpen ? (
                    <div className="user-dropdown">
                      <Link to="/profile">
                        <UserIcon size={15} /> 个人中心
                      </Link>
                      <Link to="/favorites">
                        <Bookmark size={15} /> 我的收藏
                      </Link>
                      <button
                        type="button"
                        onClick={async () => {
                          await logout();
                          navigate('/');
                        }}
                      >
                        <LogOut size={15} /> 退出登录
                      </button>
                    </div>
                  ) : null}
                </div>
              ) : (
                <Link to="/login" className="btn btn-primary">
                  登录 / 注册
                </Link>
              )}
            </div>
          </div>

          {/* 移动端展开的导航 */}
          <nav className={menuOpen ? 'mobile-nav open' : 'mobile-nav'}>
            {navLinks}
            <form onSubmit={onSearch} style={{ marginTop: 10 }}>
              <input
                value={keyword}
                onChange={(e) => setKeyword(e.target.value)}
                placeholder="搜索内容、标签"
                aria-label="搜索"
                className="auth-field"
                style={{ width: '100%' }}
              />
            </form>
          </nav>
        </div>
      </header>

      <main className="site-main">
        <div className="container">
          <Outlet />
        </div>
      </main>

      <footer className="site-footer">
        <div className="container">
          <div className="footer-inner">
            <div>
              <p className="footer-brand">ZSK 知识库</p>
              <p className="footer-desc">
                聚焦人工智能、数字经济与产业转型的观察记录，用结构化内容把复杂问题讲清楚。
              </p>
            </div>
            <div className="footer-links">
              <Link to="/">首页</Link>
              <Link to="/search">搜索</Link>
              <Link to="/favorites">我的收藏</Link>
              <a href="/admin/" target="_blank" rel="noreferrer">
                管理后台
              </a>
            </div>
          </div>
          <div className="footer-copy">© {new Date().getFullYear()} ZSK 知识库 · 内容仅供研究参考</div>
        </div>
      </footer>
    </div>
  );
}
