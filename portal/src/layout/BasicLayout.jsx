import { useEffect, useRef, useState } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  Bookmark,
  ChevronDown,
  Coins,
  Construction,
  LogOut,
  Menu,
  Moon,
  Search,
  Sun,
  User as UserIcon,
  X,
} from 'lucide-react';
import { useUserAuth } from '../store/UserAuthContext';
import logoMark from '../assets/logo.svg';

/** 待开发板块：只做入口展示，点击弹「正预备开放中」提示，不注册路由 */
const SOON_PLATES = ['活动福利', '进来玩玩', 'AI实验室'];

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
  const [soonPlate, setSoonPlate] = useState('');
  const [menuOpen, setMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const { isLogin, user, logout } = useUserAuth();
  const userMenuRef = useRef(null);

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
      {SOON_PLATES.map((name) => (
        <button
          key={name}
          type="button"
          className="nav-link nav-soon"
          onClick={() => setSoonPlate(name)}
        >
          {name}
        </button>
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
              <img className="brand-mark" src={logoMark} alt="MCYY 知识库" />
              <span>MCYY 知识库</span>
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
                      <Link to="/points">
                        <Coins size={15} /> 积分明细
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
              <p className="footer-brand">MCYY 知识库</p>
              <p className="footer-desc">
                聚焦人工智能、数字经济与产业转型的观察记录，用结构化内容把复杂问题讲清楚。
              </p>
            </div>
            <div className="footer-links">
              <Link to="/">首页</Link>
              <Link to="/search">搜索</Link>
              <Link to="/favorites">我的收藏</Link>
            {/* 不再放后台入口：前台是对外站点，/admin 的地址不该出现在公开页面上 */}
            </div>
          </div>
          <div className="footer-copy">
            © {new Date().getFullYear()} MCYY 知识库 · 内容仅供研究参考
            {/* ICP 备案号：大陆服务器托管属管局硬性要求，会不定期抽查 */}
            <a
              href="https://beian.miit.gov.cn/"
              target="_blank"
              rel="noopener noreferrer"
              className="footer-icp"
            >
              浙ICP备2026047213号-2
            </a>
            {/* 公安联网备案：图标用公安部备案系统官方图，链接指向该备案号的官方查询页 */}
            <a
              href="https://beian.mps.gov.cn/#/query/webSearch?code=33010202006522"
              target="_blank"
              rel="noopener noreferrer"
              className="footer-police"
            >
              <img
                src="https://beian.mps.gov.cn/img/logo01.dd7ff50e.png"
                alt="公安备案"
                loading="lazy"
              />
              浙公网安备33010202006522号
            </a>
          </div>
        </div>
      </footer>

      {/* 占位板块提示框：点击遮罩或按钮关闭，不注册任何路由 */}
      {soonPlate ? (
        <div className="soon-mask" onClick={() => setSoonPlate('')} role="dialog" aria-modal="true">
          <div className="soon-modal" onClick={(e) => e.stopPropagation()}>
            <span className="soon-ico">
              <Construction size={26} />
            </span>
            <p className="soon-title">正预备开放中</p>
            <p className="soon-desc">「{soonPlate}」板块正在筹备，敬请期待</p>
            <button type="button" className="btn btn-primary" onClick={() => setSoonPlate('')}>
              我知道了
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
