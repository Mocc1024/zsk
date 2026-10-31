import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';

/**
 * 404 页面
 */
export default function NotFound() {
  return (
    <div className="card">
      <div className="notfound">
        <Compass size={48} className="empty-icon" />
        <div className="notfound-code">404</div>
        <p className="empty-title">这个页面不存在，或者已经被移走了</p>
        <div style={{ marginTop: 20, display: 'flex', gap: 10, justifyContent: 'center' }}>
          <Link to="/" className="btn btn-primary">
            返回首页
          </Link>
          <Link to="/search" className="btn">
            搜索内容
          </Link>
        </div>
      </div>
    </div>
  );
}
