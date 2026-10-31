import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { SearchX, Search } from 'lucide-react';
import ContentCard from '../components/ContentCard';
import { frontApi } from '../api';

const PAGE_SIZE = 9;

/**
 * 搜索结果页
 * 高亮在 ContentCard 里做（Highlight 组件），避免用 innerHTML 拼标签。
 */
export default function SearchPage() {
  const [params, setParams] = useSearchParams();
  const keyword = params.get('q') || '';
  const [input, setInput] = useState(keyword);
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // 地址栏的关键词变了（比如从标签云点进来）就同步到输入框
  useEffect(() => {
    setInput(keyword);
    setPage(1);
  }, [keyword]);

  useEffect(() => {
    if (!keyword) {
      setList([]);
      setTotal(0);
      return;
    }

    let alive = true;
    setLoading(true);

    frontApi
      .search({ keyword, page, limit: PAGE_SIZE })
      .then((res) => {
        if (!alive) {
          return;
        }
        setList(res.data.list || []);
        setTotal(res.data.total || 0);
      })
      .catch((e) => alive && setError(e.message || '搜索失败'))
      .finally(() => alive && setLoading(false));

    return () => {
      alive = false;
    };
  }, [keyword, page]);

  const submit = (e) => {
    e.preventDefault();
    setParams(input.trim() ? { q: input.trim() } : {});
  };

  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  return (
    <>
      <div className="page-head">
        <h1 className="page-head-title">
          {keyword ? `搜索：${keyword}` : '搜索'}
        </h1>
        <p className="page-head-desc">支持标题、摘要与正文关键词；正文由全文索引匹配，最少两个字。</p>
      </div>

      <form className="card" onSubmit={submit} style={{ marginBottom: 20 }}>
        <div style={{ display: 'flex', gap: 10 }}>
          <div className="auth-field" style={{ flex: 1, marginBottom: 0 }}>
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="输入关键词，回车搜索"
              aria-label="搜索关键词"
            />
          </div>
          <button className="btn btn-primary" type="submit" style={{ height: 40, padding: '0 24px' }}>
            <Search size={16} /> 搜索
          </button>
        </div>
      </form>

      {!keyword ? (
        <div className="card">
          <div className="empty">
            <Search size={36} className="empty-icon" />
            <p className="empty-title">输入关键词开始搜索</p>
          </div>
        </div>
      ) : loading ? (
        <div className="card-grid">
          {[0, 1, 2, 3, 4, 5].map((i) => (
            <div key={i} className="skeleton-card">
              <div className="skeleton skeleton-cover" />
              <div className="skeleton skeleton-line" />
              <div className="skeleton skeleton-line short" />
            </div>
          ))}
        </div>
      ) : error ? (
        <div className="card">
          <div className="empty">
            <SearchX size={36} className="empty-icon" />
            <p className="empty-title">{error}</p>
          </div>
        </div>
      ) : list.length === 0 ? (
        <div className="card">
          <div className="empty">
            <SearchX size={36} className="empty-icon" />
            <p className="empty-title">没有找到与「{keyword}」相关的内容</p>
            <p className="placeholder" style={{ marginTop: 8 }}>
              换个说法试试，或<Link to="/"> 回首页逛逛</Link>
            </p>
          </div>
        </div>
      ) : (
        <>
          <div className="toolbar">
            <span className="result-count">共 {total} 条结果</span>
          </div>

          <div className="card-grid">
            {list.map((item) => (
              <ContentCard key={item.id} item={item} keyword={keyword} />
            ))}
          </div>

          {total > PAGE_SIZE ? (
            <div className="pager">
              <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                上一页
              </button>
              <span>
                第 {page} 页 / 共 {pages} 页
              </span>
              <button type="button" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
                下一页
              </button>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}
