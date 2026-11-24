import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight, BookOpen, Database, Flame, Inbox, PenTool, Search, Server } from 'lucide-react';
import { frontApi } from '../api';

/** 分类入口卡图标与渐变色，按序循环分配 */
const CAT_ICONS = [BookOpen, Database, Server, PenTool];
const CAT_COLORS = ['ico-blue', 'ico-violet', 'ico-pink', 'ico-cyan'];

/** 榜单行资讯卡（最新资讯）：缩略图 + 标题 + 分类 + 摘要 + 发布时间 */
function NewsCard({ item }) {
  return (
    <Link to={`/content/${item.id}`} className="board-row">
      <div className={`board-pic${item.cover ? '' : ' ph'}`}>
        {item.cover ? <img src={item.cover} alt={item.title} loading="lazy" /> : null}
      </div>
      <div className="board-main">
        <div className="board-title-row">
          <h3>{item.title}</h3>
          {item.category_name ? <span className="board-tag">{item.category_name}</span> : null}
        </div>
        {item.summary ? <p className="board-sum">{item.summary}</p> : null}
        <span className="board-more">阅读全文 ›</span>
      </div>
      {item.publish_time ? (
        <div className="board-time">
          <b>{String(item.publish_time).slice(0, 10)}</b>
          <span>{String(item.publish_time).slice(11, 19)}</span>
        </div>
      ) : null}
    </Link>
  );
}

/** 首屏骨架：结构与真实内容一致，避免加载完成时布局跳动 */
function HomeSkeleton() {
  return (
    <div>
      <div className="home-hero">
        <div className="skeleton" style={{ width: 380, height: 30, margin: '0 auto' }} />
        <div className="skeleton" style={{ width: 240, height: 14, margin: '18px auto 0' }} />
        <div className="skeleton" style={{ width: 560, height: 54, margin: '30px auto 0', borderRadius: 999 }} />
      </div>
      <div className="explore-grid">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="skeleton-card" style={{ height: 88 }} />
        ))}
      </div>
      <div className="sec-center">
        <div className="skeleton" style={{ width: 160, height: 24 }} />
      </div>
      <div className="featured-grid">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="skeleton-card" style={{ height: 200 }} />
        ))}
      </div>
      <div className="sec-center" style={{ marginTop: 32 }}>
        <div className="skeleton" style={{ width: 160, height: 24 }} />
      </div>
      <div className="board">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="skeleton-card" style={{ height: 114, borderRadius: 0 }} />
        ))}
      </div>
    </div>
  );
}

/**
 * 首页（2026.11 AI 科技风改版）：
 * Hero 品牌区 + 搜索 → 探索知识库（分类入口卡）→ 文章资讯（置顶角标 + 最新）→ 热门阅读
 */
export default function Home() {
  const [data, setData] = useState({ top: [], latest: [], hot: [], categories: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [keyword, setKeyword] = useState('');
  const navigate = useNavigate();

  useEffect(() => {
    let alive = true;
    frontApi
      .home()
      .then((res) => {
        if (alive) {
          setData({
            top: res.data.top || [],
            latest: res.data.latest || [],
            hot: res.data.hot || [],
            categories: res.data.categories || [],
          });
        }
      })
      .catch((e) => alive && setError(e.message || '加载失败'))
      .finally(() => alive && setLoading(false));
    return () => {
      alive = false;
    };
  }, []);

  const onSearch = (e) => {
    e.preventDefault();
    const q = keyword.trim();
    navigate(q ? `/search?q=${encodeURIComponent(q)}` : '/search');
  };

  if (loading) {
    return <HomeSkeleton />;
  }

  if (error) {
    return (
      <div className="card">
        <div className="empty">
          <Inbox size={40} className="empty-icon" />
          <p className="empty-title">{error}</p>
          <Link to="/search" className="btn">
            去搜索
          </Link>
        </div>
      </div>
    );
  }

  // 精选推荐 = 置顶文章；最新资讯 = latest（接口按发布时间倒序，置顶不参与排序）
  const featured = data.top.slice(0, 6);
  const latestList = data.latest.slice(0, 10);

  return (
    <>
      {/* Hero 品牌区：渐变光效 + 居中搜索 */}
      <section className="home-hero">
        <div className="home-hero-art" aria-hidden="true">
          <span className="hero-ring" />
          <span className="hero-orb">M</span>
          <span className="hero-cube c1" />
          <span className="hero-cube c2" />
          <span className="hero-cube c3" />
        </div>
        <h1 className="home-hero-title">
          让知识成为你的<span className="hl">第二大脑</span>
        </h1>
        <p className="home-hero-sub">结构化内容检索与阅读 · 只写经过验证的经验</p>
        <form className="home-hero-search" onSubmit={onSearch}>
          <Search size={18} className="search-ico" />
          <input
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            placeholder="搜索内容、分类或标签…"
            aria-label="搜索"
          />
          <button type="submit" className="home-hero-btn">
            开始搜索 <ArrowRight size={15} />
          </button>
        </form>
      </section>

      {/* 探索知识库：分类入口卡（数据来自 categories 接口） */}
      {data.categories.length ? (
        <section className="section">
          <div className="sec-center">
            <span className="sec-ln" />
            <h2 className="sec-center-title">
              <span className="hl">探索</span>知识库
            </h2>
            <span className="sec-ln r" />
          </div>
          <div className="explore-grid">
            {data.categories.map((cat, index) => {
              const Icon = CAT_ICONS[index % CAT_ICONS.length];
              const color = CAT_COLORS[index % CAT_COLORS.length];
              return (
                <Link key={cat.id} to={`/category/${cat.id}`} className="explore-card">
                  <span className={`explore-ico ${color}`}>
                    <Icon size={16} />
                  </span>
                  <span className="explore-txt">
                    <b>{cat.name}</b>
                    <i>{cat.content_count ?? 0} 篇文章</i>
                  </span>
                  <span className="explore-go">
                    <ArrowRight size={16} />
                  </span>
                </Link>
              );
            })}
          </div>
        </section>
      ) : null}

      {/* 精选推荐：后台置顶文章 */}
      {featured.length ? (
        <section className="section">
          <div className="sec-center">
            <span className="sec-ln" />
            <h2 className="sec-center-title">
              <span className="hl">精选</span>推荐
            </h2>
            <span className="sec-ln r" />
          </div>
          <div className="featured-grid">
            {featured.map((item) => (
              <Link key={item.id} to={`/content/${item.id}`} className="hot-card">
                <div className={`hot-cover${item.cover ? '' : ' ph'}`}>
                  {item.cover ? <img src={item.cover} alt={item.title} loading="lazy" /> : null}
                  {item.category_name ? <span className="hot-cat">{item.category_name}</span> : null}
                </div>
                <div className="hot-body">
                  <h4>{item.title}</h4>
                  <div className="hot-views">{item.views ?? 0} 次浏览</div>
                </div>
              </Link>
            ))}
          </div>
        </section>
      ) : null}

      {/* 最新资讯：只按发布时间倒序，置顶不提前 */}
      <section className="section">
        <div className="sec-center">
          <span className="sec-ln" />
          <h2 className="sec-center-title">最新资讯</h2>
          <span className="sec-ln r" />
        </div>
        {latestList.length ? (
          <>
            <div className="board">
              {latestList.map((item) => (
                <NewsCard key={item.id} item={item} />
              ))}
            </div>
            <div className="more-wrap">
              <Link to="/search" className="more-btn">
                浏览全部内容 <ArrowRight size={14} />
              </Link>
            </div>
          </>
        ) : featured.length ? null : (
          <div className="empty">
            <Inbox size={36} className="empty-icon" />
            <p className="empty-title">还没有已发布的内容</p>
          </div>
        )}
      </section>

      {/* 热门阅读：hot 接口前 5 条 */}
      {data.hot.length ? (
        <section className="section">
          <div className="sec-center">
            <span className="sec-ln" />
            <h2 className="sec-center-title">热门阅读</h2>
            <span className="sec-ln r" />
          </div>
          {/* 排行列表：两列，每列 5 条，序号 + 标题 + 浏览量 */}
          <div className="rank-panel">
            {data.hot.slice(0, 10).map((item, index) => (
              <Link key={item.id} to={`/content/${item.id}`} className="rank-row">
                <span className={`rank-order${index < 3 ? ` top-${index + 1}` : ''}`}>
                  {index + 1}
                </span>
                <span className="rank-text">{item.title}</span>
                <span className="rank-views">
                  <Flame size={13} />
                  {item.views ?? 0}
                </span>
              </Link>
            ))}
          </div>
        </section>
      ) : null}
    </>
  );
}
