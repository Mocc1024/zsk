import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Compass, Inbox } from 'lucide-react';
import ContentCard from '../components/ContentCard';
import { frontApi } from '../api';

/** 首屏骨架：结构与真实内容一致，避免加载完成时布局跳动 */
function HomeSkeleton() {
  return (
    <div>
      <div className="hero">
        <div className="skeleton-card">
          <div className="skeleton skeleton-cover" style={{ height: 340 }} />
          <div className="skeleton skeleton-line" />
          <div className="skeleton skeleton-line short" />
        </div>
        <div className="hero-side">
          {[0, 1, 2].map((i) => (
            <div key={i} className="skeleton-card" style={{ height: 94 }} />
          ))}
        </div>
      </div>
      <div className="card-grid">
        {[0, 1, 2, 3, 4, 5].map((i) => (
          <div key={i} className="skeleton-card">
            <div className="skeleton skeleton-cover" />
            <div className="skeleton skeleton-line" />
            <div className="skeleton skeleton-line short" />
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * 首页：头条 + 分类导航 + 最新发布 + 热门侧栏
 */
export default function Home() {
  const [data, setData] = useState({ top: [], latest: [], hot: [], categories: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

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

    // 组件卸载后不要再 setState，否则切页太快会有警告
    return () => {
      alive = false;
    };
  }, []);

  // 标签云没有独立接口，从已加载的内容里聚合去重（最多 12 个）
  const tags = useMemo(() => {
    const all = [...data.top, ...data.latest, ...data.hot];
    const counted = new Map();
    all.forEach((item) => {
      (item.tags || []).forEach((tag) => {
        counted.set(tag, (counted.get(tag) || 0) + 1);
      });
    });

    return [...counted.entries()].sort((a, b) => b[1] - a[1]).slice(0, 12).map(([tag]) => tag);
  }, [data]);

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

  const [hero, ...restTop] = data.top;
  const sideTop = restTop.slice(0, 3);

  return (
    <>
      {hero ? (
        <section className="hero">
          <Link to={`/content/${hero.id}`} className="hero-main">
            <div className="hero-cover">
              {hero.cover ? <img src={hero.cover} alt={hero.title} /> : null}
              <span className="hero-badge">置顶推荐</span>
            </div>
            <div className="hero-body">
              <h1 className="hero-title">{hero.title}</h1>
              <p className="hero-summary">{hero.summary}</p>
              <div className="hero-meta">
                {hero.category_name ? <span className="tag-chip">{hero.category_name}</span> : null}
                {hero.author ? <span>{hero.author}</span> : null}
                {hero.publish_time ? <span>{String(hero.publish_time).slice(0, 10)}</span> : null}
                <span>{hero.views ?? 0} 次浏览</span>
              </div>
            </div>
          </Link>

          {sideTop.length ? (
            <div className="hero-side">
              {sideTop.map((item) => (
                <Link key={item.id} to={`/content/${item.id}`} className="hero-side-item">
                  <div className="hero-side-cover">
                    {item.cover ? <img src={item.cover} alt={item.title} loading="lazy" /> : null}
                  </div>
                  <div style={{ minWidth: 0 }}>
                    <h3 className="hero-side-title">{item.title}</h3>
                    <div className="hero-side-meta">
                      {item.category_name || ''} · {item.views ?? 0} 次浏览
                    </div>
                  </div>
                </Link>
              ))}
            </div>
          ) : null}
        </section>
      ) : null}

      {data.categories.length ? (
        <section className="section">
          <div className="section-head">
            <h2 className="section-title">按分类浏览</h2>
            <span className="section-more">
              <Compass size={13} style={{ verticalAlign: -2 }} /> 共 {data.categories.length} 个分类
            </span>
          </div>
          <div className="category-tiles">
            {data.categories.map((cat) => (
              <Link key={cat.id} to={`/category/${cat.id}`} className="category-tile">
                <p className="tile-name">{cat.name}</p>
                {cat.description ? <p className="tile-desc">{cat.description}</p> : null}
                <span className="tile-count">{cat.content_count ?? 0} 篇内容</span>
              </Link>
            ))}
          </div>
        </section>
      ) : null}

      <div className="layout-split">
        <div>
          <section className="section">
            <div className="section-head">
              <h2 className="section-title">最新发布</h2>
            </div>
            {data.latest.length ? (
              <div className="card-grid">
                {data.latest.map((item) => (
                  <ContentCard key={item.id} item={item} />
                ))}
              </div>
            ) : (
              <div className="empty">
                <Inbox size={36} className="empty-icon" />
                <p className="empty-title">还没有已发布的内容</p>
              </div>
            )}
          </section>
        </div>

        <aside>
          {data.hot.length ? (
            <div className="side-card">
              <h3 className="side-title">热门排行</h3>
              <div className="rank-list">
                {data.hot.slice(0, 8).map((item, index) => (
                  <Link key={item.id} to={`/content/${item.id}`} className="rank-item">
                    <span className="rank-num">{index + 1}</span>
                    <div style={{ minWidth: 0 }}>
                      <div className="rank-title">{item.title}</div>
                      <div className="rank-meta">{item.views ?? 0} 次浏览</div>
                    </div>
                  </Link>
                ))}
              </div>
            </div>
          ) : null}

          {tags.length ? (
            <div className="side-card">
              <h3 className="side-title">热门标签</h3>
              <div className="tag-cloud">
                {tags.map((tag) => (
                  <Link key={tag} to={`/search?q=${encodeURIComponent(tag)}`}>
                    {tag}
                  </Link>
                ))}
              </div>
            </div>
          ) : null}
        </aside>
      </div>
    </>
  );
}
