import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookmarkX, Inbox } from 'lucide-react';
import { favoriteApi } from '../api';

/**
 * 我的收藏
 */
export default function Favorites() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = () => {
    favoriteApi
      .list({ limit: 20 })
      .then((res) => {
        setList(res.data.list || []);
        setTotal(res.data.total || 0);
      })
      .catch((e) => setError(e.message || '加载失败'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const remove = async (contentId) => {
    try {
      await favoriteApi.remove(contentId);
      setList((prev) => prev.filter((item) => Number(item.content_id) !== Number(contentId)));
      setTotal((prev) => Math.max(0, prev - 1));
    } catch (e) {
      setError(e.message || '取消失败');
    }
  };

  return (
    <>
      <nav className="breadcrumb">
        <Link to="/">首页</Link>
        <span>/</span>
        <span>我的收藏</span>
      </nav>

      <div className="page-head">
        <h1 className="page-head-title">我的收藏（{total}）</h1>
        <p className="page-head-desc">收藏的内容会一直留在这里，取消收藏后不再显示。</p>
      </div>

      {loading ? (
        <div className="card-grid">
          {[0, 1, 2].map((i) => (
            <div key={i} className="skeleton-card">
              <div className="skeleton skeleton-cover" />
              <div className="skeleton skeleton-line" />
              <div className="skeleton skeleton-line short" />
            </div>
          ))}
        </div>
      ) : error ? (
        <div className="card">
          <p className="auth-error">{error}</p>
        </div>
      ) : list.length === 0 ? (
        <div className="card">
          <div className="empty">
            <Inbox size={36} className="empty-icon" />
            <p className="empty-title">还没有收藏任何内容</p>
            <Link to="/" className="btn">
              去首页看看
            </Link>
          </div>
        </div>
      ) : (
        <div className="card-grid">
          {list.map((item) => (
            <div key={item.id} className="post-card">
              {item.cover ? (
                <div className="post-cover">
                  <img src={item.cover} alt={item.title || '内容封面'} loading="lazy" />
                </div>
              ) : null}
              <div className="post-body">
                {item.category_name ? (
                  <span className="tag-chip" style={{ alignSelf: 'flex-start' }}>
                    {item.category_name}
                  </span>
                ) : null}
                <h3 className="post-title">
                  <Link to={`/content/${item.content_id}`}>{item.title || '（内容已删除）'}</Link>
                </h3>
                {item.summary ? <p className="post-summary">{item.summary}</p> : null}
                <div className="post-meta">
                  <span>收藏于 {String(item.create_time || '').slice(0, 10)}</span>
                </div>
                <div className="post-tags">
                  <button
                    type="button"
                    className="link-danger"
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
                    onClick={() => remove(item.content_id)}
                  >
                    <BookmarkX size={14} /> 取消收藏
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  );
}
