import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Inbox } from 'lucide-react';
import ContentCard from '../components/ContentCard';
import { frontApi } from '../api';

const PAGE_SIZE = 9;

/**
 * 分类 / 标签共用的列表页
 *
 * 两个入口的数据形态完全一样（都是按条件过滤内容），
 * 与其复制两遍，不如共用一个组件、由路由决定过滤条件。
 */
export default function ContentList({ mode }) {
  const { id } = useParams();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState('latest');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [title, setTitle] = useState('');
  const [desc, setDesc] = useState('');

  useEffect(() => {
    let alive = true;
    setLoading(true);

    const params = { page, limit: PAGE_SIZE, sort };
    if (mode === 'category') {
      params.category_id = id;
    } else {
      params.tag_id = id;
    }

    frontApi
      .contents(params)
      .then((res) => {
        if (!alive) {
          return;
        }
        setList(res.data.list || []);
        setTotal(res.data.total || 0);
        // 分类名从列表项回带：没有内容时就没有名字，退化为通用标题
        const first = (res.data.list || [])[0];
        setTitle(mode === 'category' ? first?.category_name || '分类' : `标签 #${id}`);
        setDesc(mode === 'category' ? '' : '');
      })
      .catch((e) => alive && setError(e.message || '加载失败'))
      .finally(() => alive && setLoading(false));

    return () => {
      alive = false;
    };
  }, [id, mode, page, sort]);

  // 换排序时回到第一页，否则会出现「第 3 页 + 新的排序」这种空结果
  const changeSort = (next) => {
    setSort(next);
    setPage(1);
  };

  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  return (
    <>
      <nav className="breadcrumb">
        <Link to="/">首页</Link>
        <span>/</span>
        <span>{mode === 'category' ? '分类' : '标签'}</span>
      </nav>

      <div className="page-head">
        <h1 className="page-head-title">{title}</h1>
        {desc ? <p className="page-head-desc">{desc}</p> : null}
      </div>

      <div className="toolbar">
        <div className="sort-tabs">
          <button
            type="button"
            className={sort === 'latest' ? 'sort-tab active' : 'sort-tab'}
            onClick={() => changeSort('latest')}
          >
            最新
          </button>
          <button
            type="button"
            className={sort === 'hot' ? 'sort-tab active' : 'sort-tab'}
            onClick={() => changeSort('hot')}
          >
            最热
          </button>
        </div>
        {!loading && !error ? <span className="result-count">共 {total} 篇</span> : null}
      </div>

      {loading ? (
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
            <Inbox size={36} className="empty-icon" />
            <p className="empty-title">{error}</p>
          </div>
        </div>
      ) : list.length === 0 ? (
        <div className="card">
          <div className="empty">
            <Inbox size={36} className="empty-icon" />
            <p className="empty-title">该条件下暂无内容</p>
            <Link to="/" className="btn">
              返回首页
            </Link>
          </div>
        </div>
      ) : (
        <>
          <div className="card-grid">
            {list.map((item) => (
              <ContentCard key={item.id} item={item} />
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
