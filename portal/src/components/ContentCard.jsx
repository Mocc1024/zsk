import { Link } from 'react-router-dom';
import { BookOpen, Eye } from 'lucide-react';
import Highlight from './Highlight';

/**
 * 内容卡片（网格版）
 * 列表项统一用它渲染，避免首页 / 分类 / 搜索各写一份样式。
 * keyword 用于搜索结果高亮，走 Highlight 的安全渲染。
 */
export default function ContentCard({ item, keyword = '' }) {
  return (
    <Link to={`/content/${item.id}`} className="post-card">
      {/* 封面区：无图也要占位，保证网格排版一致；分类角标固定右上角 */}
      <div className={`post-cover${item.cover ? '' : ' post-cover-empty'}`}>
        {item.cover ? (
          <img src={item.cover} alt={item.title} loading="lazy" />
        ) : (
          <BookOpen size={38} className="post-cover-empty-icon" />
        )}
        {item.category_name ? (
          <span className="post-cover-tag">{item.category_name}</span>
        ) : null}
      </div>

      <div className="post-body">
        <h3 className="post-title">
          <Highlight text={item.title} keyword={keyword} />
        </h3>

        {item.summary ? (
          <p className="post-summary">
            <Highlight text={item.summary} keyword={keyword} />
          </p>
        ) : null}

        <div className="post-meta">
          {item.author ? <span>{item.author}</span> : null}
          {item.publish_time ? <span>{String(item.publish_time).slice(0, 10)}</span> : null}
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            <Eye size={13} /> {item.views ?? 0}
          </span>
        </div>

        {Array.isArray(item.tags) && item.tags.length ? (
          <div className="post-tags">
            {item.tags.slice(0, 3).map((tag) => (
              <span key={tag} className="tag-chip">
                {tag}
              </span>
            ))}
          </div>
        ) : null}
      </div>
    </Link>
  );
}
