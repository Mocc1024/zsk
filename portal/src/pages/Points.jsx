import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { pointApi } from '../api';
import { pointSceneLabel } from '../utils/points';

export default function Points() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [balance, setBalance] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize] = useState(10);
  const [loading, setLoading] = useState(true);

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await pointApi.points({ page, limit: pageSize });
      setList(res.data.list || []);
      setTotal(Number(res.data.total || 0));
      setBalance(Number(res.data.balance || 0));
    } finally {
      setLoading(false);
    }
  }, [page, pageSize]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const totalPages = Math.max(1, Math.ceil(total / pageSize));

  return (
    <div className="card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 className="page-title" style={{ margin: 0 }}>积分明细</h1>
        <Link to="/profile" style={{ color: 'var(--brand)', textDecoration: 'none' }}>返回个人中心</Link>
      </div>

      <div className="points-balance">
        <span>当前积分</span>
        <strong>{balance}</strong>
      </div>

      {loading ? (
        <p className="placeholder">加载中…</p>
      ) : list.length ? (
        <div className="point-detail-list">
          {list.map((row) => (
            <div key={row.id} className="point-detail-row">
              <div className="point-detail-main">
                <strong>{pointSceneLabel(row.scene)}</strong>
                <span className="point-detail-remark">{row.remark || '—'}</span>
              </div>
              <div className="point-detail-value">
                <span className={Number(row.type) === 1 ? 'gain' : 'cost'}>
                  {Number(row.type) === 1 ? '+' : '-'}{row.points}
                </span>
                <span>余额 {row.balance}</span>
              </div>
              <span className="point-log-time">{String(row.create_time || '').slice(0, 16)}</span>
            </div>
          ))}
        </div>
      ) : (
        <p className="placeholder">暂无积分变动记录</p>
      )}

      {total > pageSize ? (
        <div className="points-pagination">
          <button type="button" disabled={page <= 1 || loading} onClick={() => setPage((p) => Math.max(1, p - 1))}>
            上一页
          </button>
          <span>第 {page} / {totalPages} 页，共 {total} 条</span>
          <button type="button" disabled={page >= totalPages || loading} onClick={() => setPage((p) => Math.min(totalPages, p + 1))}>
            下一页
          </button>
        </div>
      ) : null}
    </div>
  );
}
