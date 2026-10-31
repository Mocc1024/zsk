import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Bookmark, BookmarkCheck, Eye, MessageSquare } from 'lucide-react';
import ContentCard from '../components/ContentCard';
import CommentSection from '../components/CommentSection';
import { favoriteApi, frontApi } from '../api';
import { useUserAuth } from '../store/UserAuthContext';

/**
 * 内容详情
 *
 * 正文用 dangerouslySetInnerHTML：后端在输出前已过 HtmlFilter（HTMLPurifier 主路径），
 * 前端不再二次清洗——再洗一遍会破坏富文本的排版与样式。
 * 这不是「信任用户输入」，而是信任服务端已完成的净化。
 */
export default function ContentDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { isLogin } = useUserAuth();
  const [detail, setDetail] = useState(null);
  const [related, setRelated] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [favorited, setFavorited] = useState(false);
  const [favoriteBusy, setFavoriteBusy] = useState(false);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    setRelated([]);

    frontApi
      .content(id)
      .then((res) => alive && setDetail(res.data.content))
      .catch((e) => alive && setError(e.message || '加载失败'))
      .finally(() => alive && setLoading(false));

    // 浏览上报：失败不影响阅读，只静默忽略
    frontApi.statView({ id }).catch(() => {});

    return () => {
      alive = false;
    };
  }, [id]);

  // 收藏状态：未登录时不查，避免 401 刷屏
  useEffect(() => {
    if (!isLogin || !id) {
      setFavorited(false);
      return;
    }
    let alive = true;
    favoriteApi
      .check(id)
      .then((res) => alive && setFavorited(res.data.favorited === true))
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [id, isLogin]);

  // 相关推荐：同分类的最新内容，去掉自己
  useEffect(() => {
    if (!detail?.category_id) {
      return;
    }
    let alive = true;
    frontApi
      .contents({ category_id: detail.category_id, limit: 5, sort: 'latest' })
      .then((res) => {
        if (!alive) {
          return;
        }
        const rows = (res.data.list || []).filter((row) => Number(row.id) !== Number(id));
        setRelated(rows.slice(0, 4));
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [detail?.category_id, id]);

  const toggleFavorite = async () => {
    if (!isLogin) {
      navigate('/login');
      return;
    }
    setFavoriteBusy(true);
    try {
      if (favorited) {
        await favoriteApi.remove(id);
      } else {
        await favoriteApi.add(id);
      }
      setFavorited(!favorited);
    } catch (e) {
      setError(e.message || '操作失败');
    } finally {
      setFavoriteBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="card">
        <div className="skeleton skeleton-line" style={{ height: 32, width: '60%' }} />
        <div className="skeleton skeleton-line" style={{ width: '40%' }} />
        <div className="skeleton skeleton-line" />
        <div className="skeleton skeleton-line" />
        <div className="skeleton skeleton-line" style={{ width: '80%' }} />
      </div>
    );
  }

  if (error) {
    return (
      <div className="card">
        <div className="empty">
          <p className="empty-title">{error}</p>
          <Link to="/" className="btn">
            返回首页
          </Link>
        </div>
      </div>
    );
  }

  if (!detail) {
    return (
      <div className="card">
        <div className="empty">
          <p className="empty-title">内容不存在</p>
        </div>
      </div>
    );
  }

  return (
    <>
      <nav className="breadcrumb">
        <Link to="/">首页</Link>
        <span>/</span>
        {detail.category_id ? (
          <>
            <Link to={`/category/${detail.category_id}`}>{detail.category_name}</Link>
            <span>/</span>
          </>
        ) : null}
        <span>正文</span>
      </nav>

      <article className="article">
        {detail.category_name ? (
          <Link to={`/category/${detail.category_id}`} className="tag-chip">
            {detail.category_name}
          </Link>
        ) : null}

        <h1 className="article-title" style={{ marginTop: 12 }}>
          {detail.title}
        </h1>

        <div className="article-meta">
          {detail.author ? <span>{detail.author}</span> : null}
          {detail.publish_time ? <span>{String(detail.publish_time).slice(0, 16)}</span> : null}
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            <Eye size={14} /> {detail.views ?? 0}
          </span>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            <MessageSquare size={14} /> {detail.comment_count ?? 0}
          </span>
        </div>

        {detail.cover ? (
          <div className="article-cover">
            <img src={detail.cover} alt={detail.title} />
          </div>
        ) : null}

        {detail.summary ? <p className="article-summary">{detail.summary}</p> : null}

        <div
          className="article-content"
          dangerouslySetInnerHTML={{ __html: detail.content || '' }}
        />

        {Array.isArray(detail.tags) && detail.tags.length ? (
          <div className="article-tags">
            {detail.tags.map((tag) => (
              <Link key={tag} to={`/search?q=${encodeURIComponent(tag)}`} className="tag-link">
                #{tag}
              </Link>
            ))}
          </div>
        ) : null}

        <div className="article-actions">
          <button
            type="button"
            className={favorited ? 'fav-btn active' : 'fav-btn'}
            disabled={favoriteBusy}
            onClick={toggleFavorite}
          >
            {favorited ? <BookmarkCheck size={16} /> : <Bookmark size={16} />}
            {favorited ? '已收藏' : '收藏'}
          </button>
          <Link to="/" className="btn">
            返回首页
          </Link>
        </div>
      </article>

      {related.length ? (
        <section className="section related">
          <div className="section-head">
            <h2 className="section-title">相关推荐</h2>
            {detail.category_id ? (
              <Link to={`/category/${detail.category_id}`} className="section-more">
                查看该分类全部
              </Link>
            ) : null}
          </div>
          <div className="related-grid">
            {related.map((item) => (
              <ContentCard key={item.id} item={item} />
            ))}
          </div>
        </section>
      ) : null}

      <CommentSection contentId={id} />
    </>
  );
}
