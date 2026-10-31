import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Bookmark, CheckCircle2, MessageSquare, Sparkles } from 'lucide-react';
import { userApi } from '../api';
import { useUserAuth } from '../store/UserAuthContext';

/**
 * 登录 / 注册
 *
 * 验证码每次提交失败都要换一张（服务端是一次性的），
 * 所以这里在「提交返回后」统一重新拉取，而不是只在挂载时拉一次。
 */
export default function Login() {
  const navigate = useNavigate();
  const { login, register } = useUserAuth();

  const [mode, setMode] = useState('login');
  const [form, setForm] = useState({
    username: '',
    password: '',
    confirm_password: '',
    agree: true,
  });
  const [captcha, setCaptcha] = useState({ captcha_id: '', captcha_code: '', image: '' });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const reloadCaptcha = useCallback(() => {
    setCaptcha((prev) => ({ ...prev, captcha_code: '' }));
    userApi
      .captcha()
      .then((res) =>
        setCaptcha({ captcha_id: res.data.captcha_id, captcha_code: '', image: res.data.image })
      )
      .catch(() => setError('验证码加载失败，请刷新页面重试'));
  }, []);

  useEffect(() => {
    reloadCaptcha();
  }, [reloadCaptcha, mode]);

  const setField = (key) => (e) => {
    const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const submit = async (e) => {
    e.preventDefault();
    setError('');

    if (mode === 'register') {
      if (form.password !== form.confirm_password) {
        setError('两次输入的密码不一致');
        reloadCaptcha();
        return;
      }
      if (!form.agree) {
        setError('请先阅读并同意用户协议');
        return;
      }
    }

    setSubmitting(true);
    try {
      const payload = {
        username: form.username,
        password: form.password,
        captcha_id: captcha.captcha_id,
        captcha_code: captcha.captcha_code,
      };
      if (mode === 'register') {
        await register({ ...payload, confirm_password: form.confirm_password, agree: 1 });
      } else {
        await login(payload);
      }
      navigate('/', { replace: true });
    } catch (err) {
      setError(err.message || '操作失败');
      reloadCaptcha();
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="auth-wrap">
      <div className="auth-side">
        <h1 className="auth-side-title">
          把复杂问题
          <br />
          讲清楚的那种知识库
        </h1>
        <p className="auth-side-desc">
          登录后可以收藏内容、参与评论，签到与互动还能累积积分与等级。
        </p>
        <div className="auth-side-list">
          <div className="auth-side-item">
            <Sparkles size={16} />
            <span>结构化分类，按产业与主题快速定位</span>
          </div>
          <div className="auth-side-item">
            <Bookmark size={16} />
            <span>收藏夹跨设备同步，攒下自己的阅读清单</span>
          </div>
          <div className="auth-side-item">
            <MessageSquare size={16} />
            <span>评论与点赞，和同行一起讨论观点</span>
          </div>
        </div>
      </div>

      <div className="auth-card">
        <div className="auth-tabs">
          <button
            type="button"
            className={mode === 'login' ? 'active' : ''}
            onClick={() => setMode('login')}
          >
            登录
          </button>
          <button
            type="button"
            className={mode === 'register' ? 'active' : ''}
            onClick={() => setMode('register')}
          >
            注册
          </button>
        </div>

        <form onSubmit={submit}>
          <label className="auth-field">
            <span>用户名</span>
            <input
              value={form.username}
              onChange={setField('username')}
              placeholder="4-20 位字母、数字或下划线"
              autoComplete="username"
            />
          </label>

          <label className="auth-field">
            <span>密码</span>
            <input
              type="password"
              value={form.password}
              onChange={setField('password')}
              placeholder={mode === 'register' ? '至少 8 位，含字母和数字' : '请输入密码'}
              autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
            />
          </label>

          {mode === 'register' ? (
            <>
              <label className="auth-field">
                <span>确认密码</span>
                <input
                  type="password"
                  value={form.confirm_password}
                  onChange={setField('confirm_password')}
                  placeholder="再次输入密码"
                  autoComplete="new-password"
                />
              </label>
              <label className="auth-check">
                <input type="checkbox" checked={form.agree} onChange={setField('agree')} />
                <span>我已阅读并同意用户协议</span>
              </label>
            </>
          ) : null}

          <label className="auth-field">
            <span>图形验证码</span>
            <div className="auth-captcha">
              <input
                value={captcha.captcha_code}
                onChange={(e) => setCaptcha((prev) => ({ ...prev, captcha_code: e.target.value }))}
                placeholder="请输入图中字符"
                maxLength={4}
              />
              {captcha.image ? (
                <img src={captcha.image} alt="验证码" onClick={reloadCaptcha} title="点击换一张" />
              ) : null}
            </div>
          </label>

          {error ? <p className="auth-error">{error}</p> : null}

          <button className="auth-submit" type="submit" disabled={submitting}>
            {submitting ? '处理中…' : mode === 'login' ? '登录' : '注册并登录'}
          </button>
        </form>

        <p className="auth-alt">
          {mode === 'login' ? (
            <>
              还没有账号？
              <button
                type="button"
                className="link-danger"
                style={{ color: 'var(--brand)' }}
                onClick={() => setMode('register')}
              >
                立即注册
              </button>
            </>
          ) : (
            <>
              已有账号？
              <button
                type="button"
                className="link-danger"
                style={{ color: 'var(--brand)' }}
                onClick={() => setMode('login')}
              >
                去登录
              </button>
            </>
          )}
        </p>

        <p className="auth-alt">
          <Link to="/">先去逛逛，不登录也能看</Link>
        </p>
      </div>
    </div>
  );
}
