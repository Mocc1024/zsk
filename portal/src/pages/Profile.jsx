import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Bookmark, CalendarCheck, Camera, X } from 'lucide-react';
import { pointApi, userApi } from '../api';
import { useUserAuth } from '../store/UserAuthContext';
import { pointSceneLabel } from '../utils/points';

/**
 * 个人中心
 * 修改密码成功后会强制退出——服务端已把当前令牌拉黑，留着也没用。
 */
export default function Profile() {
  const navigate = useNavigate();
  const { user, logout, setUser } = useUserAuth();

  const [nickname, setNickname] = useState(user?.nickname || '');
  const [avatar, setAvatar] = useState(user?.avatar || '');
  const [email, setEmail] = useState(user?.email || '');
  const [mobile, setMobile] = useState(user?.mobile || '');
  const [bio, setBio] = useState(user?.bio || '');
  const [gender, setGender] = useState(String(user?.gender ?? 0));
  const [birthday, setBirthday] = useState(user?.birthday || '');

  const [oldPassword, setOldPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');

  const [saving, setSaving] = useState(false);
  const [avatarPreview, setAvatarPreview] = useState(false);
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [securityError, setSecurityError] = useState('');
  const [securityMessage, setSecurityMessage] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const [signin, setSignin] = useState({ signed: false, days: 0 });
  const [levels, setLevels] = useState([]);
  const [pointLog, setPointLog] = useState([]);

  useEffect(() => {
    if (!user) return;
    setNickname(user.nickname || '');
    setAvatar(user.avatar || '');
    setEmail(user.email || '');
    setMobile(user.mobile || '');
    setBio(user.bio || '');
    setGender(String(user.gender ?? 0));
    setBirthday(user.birthday || '');
  }, [user]);

  useEffect(() => {
    pointApi.signinStatus().then((res) => setSignin(res.data)).catch(() => {});
    pointApi.levels().then((res) => setLevels(res.data.list || [])).catch(() => {});
    pointApi.points({ limit: 10 }).then((res) => setPointLog(res.data.list || [])).catch(() => {});
  }, []);

  const doSignin = async () => {
    try {
      const res = await pointApi.signin();
      setSignin({ signed: true, days: res.data.days });
      setMessage(res.msg || '签到成功');
      pointApi.points({ limit: 10 }).then((r) => setPointLog(r.data.list || []));
      // 签到会加积分与经验，顺手把用户态同步过来
      userApi.profile().then((r) => r.data.user && setUser(r.data.user)).catch(() => {});
    } catch (e) {
      setError(e.message || '签到失败');
    }
  };

  // 下一级还差多少经验
  const currentExp = Number(user?.exp ?? 0);
  const nextLevel = levels.find((lv) => currentExp < Number(lv.min_exp));
  const gap = nextLevel ? Number(nextLevel.min_exp) - currentExp : 0;
  const expPercent = nextLevel
    ? Math.min(100, Math.round((currentExp / Number(nextLevel.min_exp)) * 100))
    : 100;

  const saveProfile = async (e) => {
    e.preventDefault();
    setError('');
    setMessage('');
    setSaving(true);
    try {
      await userApi.updateProfile({ nickname, avatar, email, mobile, bio, gender: Number(gender), birthday });
      setUser({ ...user, nickname, avatar, email, mobile, bio, gender: Number(gender), birthday });
      setMessage('资料已更新');
    } catch (err) {
      setError(err.message || '保存失败');
    } finally {
      setSaving(false);
    }
  };

  const changeAvatar = async (e) => {
    const input = e.target;
    const file = input.files?.[0];
    if (!file) return;
    setError('');
    setMessage('');
    setSaving(true);
    try {
      const res = await userApi.uploadAvatar(file);
      const url = res.data?.url || '';
      setAvatar(url);
      setUser({ ...user, avatar: url });
      setMessage('头像已更新');
    } catch (err) {
      setError(err.message || '头像上传失败');
    } finally {
      setSaving(false);
      input.value = '';
    }
  };

  const changePassword = async (e) => {
    e.preventDefault();
    setSecurityError('');
    setSecurityMessage('');
    setSaving(true);
    try {
      await userApi.password({ old_password: oldPassword, new_password: newPassword });
      setPasswordOpen(false);
      setSecurityMessage('密码修改成功，请使用新密码重新登录');
      window.setTimeout(async () => {
        await logout();
        navigate('/login', { replace: true });
      }, 1800);
    } catch (err) {
      setPasswordOpen(false);
      setSecurityError(err.message || '修改失败');
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <div className="card">
        <div className="profile-hero">
          <div
            className={`profile-avatar${avatar ? ' clickable' : ''}`}
            title={avatar ? '点击查看大图' : undefined}
            role={avatar ? 'button' : undefined}
            tabIndex={avatar ? 0 : -1}
            onClick={() => avatar && setAvatarPreview(true)}
            onKeyDown={(e) => {
              if (avatar && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                setAvatarPreview(true);
              }
            }}
          >
            {avatar ? (
              <img src={avatar} alt={user?.nickname || user?.username} />
            ) : (
              String(user?.nickname || user?.username || 'U').slice(0, 1).toUpperCase()
            )}
          </div>
          <div style={{ minWidth: 0 }}>
            <h1 className="profile-name">{user?.nickname || user?.username}</h1>
            <div className="profile-sub">
              <span className="level-badge">Lv.{user?.level ?? 1}</span>
              <span>@{user?.username}</span>
              {user?.bio ? <span>· {user.bio}</span> : null}
            </div>
          </div>
          <div style={{ marginLeft: 'auto' }}>
            <Link to="/favorites" className="btn">
              <Bookmark size={15} /> 我的收藏
            </Link>
          </div>
        </div>

        <div className="profile-stats">
          <div className="stat">
            <div className="stat-value">{user?.points ?? 0}</div>
            <div className="stat-label">积分</div>
          </div>
          <div className="stat">
            <div className="stat-value">{user?.exp ?? 0}</div>
            <div className="stat-label">经验</div>
          </div>
          <div className="stat">
            <div className="stat-value">Lv.{user?.level ?? 1}</div>
            <div className="stat-label">等级</div>
          </div>
        </div>

        <div style={{ marginTop: 18 }}>
          <div className="exp-bar">
            <div className="exp-fill" style={{ width: `${expPercent}%` }} />
          </div>
          <p className="exp-hint">
            {nextLevel ? `距 Lv.${nextLevel.level} 还差 ${gap} 经验` : '已达最高等级'}
          </p>
        </div>

        <div className="signin-row">
          <button
            type="button"
            className={signin.signed ? 'fav-btn active' : 'btn btn-primary'}
            disabled={signin.signed}
            onClick={doSignin}
          >
            <CalendarCheck size={16} />
            {signin.signed ? `今日已签到（连续 ${signin.days} 天）` : '每日签到'}
          </button>
        </div>

        {pointLog.length ? (
          <div className="point-log">
            <h3 style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span>最近积分变动</span>
              <Link to="/points" style={{ color: 'var(--brand)', fontSize: 13, textDecoration: 'none' }}>
                查看全部
              </Link>
            </h3>
            {pointLog.map((row) => (
              <div key={row.id} className="point-log-row">
                <span className="scene">{pointSceneLabel(row.scene)}</span>
                <span className={Number(row.type) === 1 ? 'gain' : 'cost'}>
                  {Number(row.type) === 1 ? '+' : '-'}
                  {row.points}
                </span>
                <span className="point-log-time">{String(row.create_time || '').slice(0, 16)}</span>
              </div>
            ))}
          </div>
        ) : null}
      </div>

      <div className="card">
        <h2 className="page-title">基本资料</h2>
        <form onSubmit={saveProfile}>
          <div className="form-grid">
            <label className="auth-field">
              <span>昵称</span>
              <input value={nickname} onChange={(e) => setNickname(e.target.value)} maxLength={20} />
            </label>
            <label className="auth-field">
              <span>邮箱</span>
              <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={100} />
            </label>
            <label className="auth-field">
              <span>手机号</span>
              <input type="tel" value={mobile} onChange={(e) => setMobile(e.target.value)} maxLength={11} />
            </label>
            <label className="auth-field">
              <span>性别</span>
              <select value={gender} onChange={(e) => setGender(e.target.value)}>
                <option value="0">未知</option>
                <option value="1">男</option>
                <option value="2">女</option>
              </select>
            </label>
            <label className="auth-field">
              <span>生日</span>
              <input type="date" value={birthday || ''} onChange={(e) => setBirthday(e.target.value)} />
            </label>
            <div className="auth-field">
              <span>头像</span>
              <label className="avatar-upload">
                <Camera size={16} />
                <span>{saving ? '上传中…' : '选择图片'}</span>
                <input type="file" accept="image/*" onChange={changeAvatar} disabled={saving} />
              </label>
            </div>
            <label className="auth-field field-wide">
              <span>简介</span>
              <textarea rows={3} value={bio} onChange={(e) => setBio(e.target.value)} maxLength={255} placeholder="介绍一下自己" />
            </label>
          </div>

          {error ? <p className="auth-error">{error}</p> : null}
          {message ? <p className="auth-success">{message}</p> : null}

          <button className="auth-submit" type="submit" disabled={saving} style={{ maxWidth: 200 }}>
            保存资料
          </button>
        </form>
      </div>

      <div className="card">
        <h2 className="page-title">账号安全</h2>
        <p className="placeholder">修改密码会在弹窗中确认，避免误操作。</p>
        {securityError ? <p className="auth-error">{securityError}</p> : null}
        {securityMessage ? <p className="auth-success">{securityMessage}</p> : null}
        <div className="account-actions">
          <button
            className="auth-submit"
            type="button"
            onClick={() => {
              setOldPassword('');
              setNewPassword('');
              setSecurityError('');
              setSecurityMessage('');
              setPasswordOpen(true);
            }}
          >
            修改密码
          </button>
        </div>
      </div>

      {passwordOpen ? (
        <div className="modal-overlay" onClick={() => setPasswordOpen(false)}>
          <div className="modal-card" role="dialog" aria-modal="true" aria-label="修改密码" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>修改密码</h3>
              <button type="button" className="modal-close" aria-label="关闭" onClick={() => setPasswordOpen(false)}>
                <X size={20} />
              </button>
            </div>
            <form onSubmit={changePassword}>
              <label className="auth-field">
                <span>原密码</span>
                <input type="password" value={oldPassword} onChange={(e) => setOldPassword(e.target.value)} />
              </label>
              <label className="auth-field">
                <span>新密码</span>
                <input
                  type="password"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  placeholder="至少 8 位，含字母和数字"
                />
              </label>
              <div className="modal-actions">
                <button type="button" className="auth-submit ghost" onClick={() => setPasswordOpen(false)}>取消</button>
                <button className="auth-submit wide" type="submit" disabled={saving}>修改并重新登录</button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      {avatarPreview && avatar ? (
        <div
          className="avatar-preview"
          role="dialog"
          aria-modal="true"
          aria-label="头像大图"
          onClick={() => setAvatarPreview(false)}
        >
          <img src={avatar} alt="头像大图" onClick={(e) => e.stopPropagation()} />
          <button
            type="button"
            className="avatar-preview-close"
            aria-label="关闭"
            onClick={() => setAvatarPreview(false)}
          >
            <X size={22} />
          </button>
        </div>
      ) : null}
    </>
  );
}
