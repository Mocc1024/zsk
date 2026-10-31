import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { commentApi } from '../api';
import { useUserAuth } from '../store/UserAuthContext';

/**
 * 评论区（楼中楼两级）
 *
 * 未登录也能看，发表/点赞/举报需要登录 —— 这三个操作由服务端判定，
 * 前端只是把入口藏起来，避免用户点了才发现要登录。
 */
export default function CommentSection({ contentId }) {
  const { isLogin } = useUserAuth();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [text, setText] = useState('');
  const [replyTo, setReplyTo] = useState(null);
  const [replyText, setReplyText] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = () => {
    commentApi
      .list(contentId, { limit: 20 })
      .then((res) => {
        setList(res.data.list || []);
        setTotal(res.data.total || 0);
      })
      .catch(() => setError('评论加载失败'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [contentId]);

  const submitTop = async (e) => {
    e.preventDefault();
    if (!isLogin) {
      setError('请先登录');
      return;
    }
    setSubmitting(true);
    setError('');
    setNotice('');
    try {
      const res = await commentApi.create({ content_id: Number(contentId), content: text });
      setNotice(res.msg || '评论已发布');
      setText('');
      load();
    } catch (err) {
      setError(err.message || '发表失败');
    } finally {
      setSubmitting(false);
    }
  };

  const submitReply = async (parentId) => {
    if (!isLogin) {
      setError('请先登录');
      return;
    }
    setSubmitting(true);
    try {
      const res = await commentApi.create({
        content_id: Number(contentId),
        content: replyText,
        pid: parentId,
      });
      setNotice(res.msg || '回复已发布');
      setReplyTo(null);
      setReplyText('');
      load();
    } catch (err) {
      setError(err.message || '回复失败');
    } finally {
      setSubmitting(false);
    }
  };

  const toggleLike = async (item) => {
    if (!isLogin) {
      setError('请先登录');
      return;
    }
    try {
      if (item.liked) {
        await commentApi.unlike(item.id);
      } else {
        await commentApi.like(item.id);
      }
      load();
    } catch (err) {
      setError(err.message || '操作失败');
    }
  };

  const report = async (item) => {
    if (!isLogin) {
      setError('请先登录');
      return;
    }
    const reason = window.prompt('请输入举报理由');
    if (!reason) {
      return;
    }
    try {
      await commentApi.report({ target_type: 'comment', target_id: item.id, reason });
      setNotice('举报已提交');
    } catch (err) {
      setError(err.message || '举报失败');
    }
  };

  const renderComment = (item, isReply = false) => (
    <div key={item.id} className={`comment-item${isReply ? ' is-reply' : ''}`}>
      <div className="comment-head">
        <strong>{item.nickname || '匿名'}</strong>
        {item.level ? <span className="tag-chip">Lv.{item.level}</span> : null}
        <span className="comment-time">{String(item.create_time || '').slice(0, 16)}</span>
      </div>
      <div className="comment-body">{item.content}</div>
      <div className="comment-actions">
        <button type="button" onClick={() => toggleLike(item)}>
          {item.liked ? '已赞' : '点赞'} {item.like_count || 0}
        </button>
        {!isReply ? (
          <button type="button" onClick={() => setReplyTo(replyTo === item.id ? null : item.id)}>
            回复
          </button>
        ) : null}
        <button type="button" onClick={() => report(item)}>
          举报
        </button>
      </div>

      {replyTo === item.id ? (
        <div className="comment-reply-box">
          <textarea
            value={replyText}
            onChange={(e) => setReplyText(e.target.value)}
            placeholder="写下你的回复…"
            maxLength={500}
          />
          <button type="button" disabled={submitting} onClick={() => submitReply(item.id)}>
            提交回复
          </button>
        </div>
      ) : null}

      {!isReply && Array.isArray(item.replies) && item.replies.length
        ? item.replies.map((reply) => renderComment(reply, true))
        : null}
    </div>
  );

  return (
    <div className="card">
      <h2 className="page-title">评论（{total}）</h2>

      {error ? <p className="auth-error">{error}</p> : null}
      {notice ? <p className="auth-success">{notice}</p> : null}

      {isLogin ? (
        <form className="comment-form" onSubmit={submitTop}>
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder="写下你的评论…"
            maxLength={500}
          />
          <button type="submit" disabled={submitting || text.trim() === ''}>
            {submitting ? '提交中…' : '发表评论'}
          </button>
        </form>
      ) : (
        <p className="placeholder">
          <Link to="/login">登录</Link> 后发表评论
        </p>
      )}

      {loading ? (
        <p className="placeholder">加载中…</p>
      ) : list.length === 0 ? (
        <p className="placeholder">还没有评论，来说两句吧</p>
      ) : (
        list.map((item) => renderComment(item))
      )}
    </div>
  );
}
