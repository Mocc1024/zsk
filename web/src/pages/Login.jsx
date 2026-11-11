import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Form, Input, Typography, message } from 'antd';
import { ArrowLeftOutlined, LockOutlined, SafetyCertificateOutlined, UserOutlined } from '@ant-design/icons';
import { authApi } from '../api';
import { useAuth } from '../store/AuthContext';
import bgImage from '../assets/login-bg.jpg';

export default function Login() {
  const navigate = useNavigate();
  const { setSession } = useAuth();
  const [loading, setLoading] = useState(false);
  // 账号开启两步验证时，登录会分成两步：先拿 ticket，再拿 ticket + 验证码换令牌
  const [ticket, setTicket] = useState('');
  const [username, setUsername] = useState('');

  const finishLogin = (data) => {
    setSession(data.token, data.admin, data.permissions || [], data.menus || []);
    message.success(`欢迎回来，${data.admin.nickname || data.admin.username}`);
    navigate('/dashboard', { replace: true });
  };

  const onFinish = async (values) => {
    setLoading(true);
    try {
      const res = await authApi.login(values);

      // 后端只发了票据，说明这个账号开了两步验证。
      // 注意此时前端没有 token，也不能自己跳转。
      if (res.data?.needTotp) {
        setTicket(res.data.ticket);
        setUsername(values.username);
        message.info('该账号已开启两步验证，请输入验证器中的 6 位数字');
        return;
      }

      finishLogin(res.data);
    } catch (e) {
      // 错误提示已在拦截器中统一处理
    } finally {
      setLoading(false);
    }
  };

  const onTotpFinish = async (values) => {
    setLoading(true);
    try {
      const res = await authApi.totpLogin({ ticket, code: values.code });
      finishLogin(res.data);
    } catch (e) {
      // 票据过期或码错误都会走到这里，提示由拦截器给出
    } finally {
      setLoading(false);
    }
  };

  // 返回上一步：票据作废即可，服务端 5 分钟后自动过期
  const backToPassword = () => {
    setTicket('');
    setUsername('');
  };

  return (
    <div className="login-page" style={{ backgroundImage: `url(${bgImage})` }}>
      <div className="login-card">
        <Typography.Title level={3} className="login-title">
          MCYY 内容管理后台
        </Typography.Title>
        <div className="login-subtitle">
          {ticket ? `两步验证 · ${username}` : '请使用管理员账号登录'}
        </div>

        {ticket ? (
          <Form size="large" onFinish={onTotpFinish} autoComplete="off">
            <Form.Item
              name="code"
              rules={[
                { required: true, message: '请输入 6 位验证码' },
                { pattern: /^\d{6}$/, message: '验证码是 6 位数字' },
              ]}
            >
              <Input
                prefix={<SafetyCertificateOutlined />}
                placeholder="验证器 App 中的 6 位数字"
                maxLength={6}
                autoFocus
                inputMode="numeric"
                style={{ letterSpacing: 6, fontSize: 18, textAlign: 'center' }}
              />
            </Form.Item>
            <Form.Item style={{ marginBottom: 12 }}>
              <Button type="primary" htmlType="submit" block loading={loading}>
                验 证
              </Button>
            </Form.Item>
            <Button type="link" block icon={<ArrowLeftOutlined />} onClick={backToPassword}>
              返回，重新输入账号密码
            </Button>
          </Form>
        ) : (
          <Form size="large" onFinish={onFinish}>
            <Form.Item name="username" rules={[{ required: true, message: '请输入登录账号' }]}>
              <Input prefix={<UserOutlined />} placeholder="登录账号" autoComplete="username" />
            </Form.Item>
            <Form.Item name="password" rules={[{ required: true, message: '请输入登录密码' }]}>
              <Input.Password prefix={<LockOutlined />} placeholder="登录密码" autoComplete="current-password" />
            </Form.Item>
            <Form.Item style={{ marginBottom: 12 }}>
              <Button type="primary" htmlType="submit" block loading={loading}>
                登 录
              </Button>
            </Form.Item>
          </Form>
        )}

      </div>
    </div>
  );
}
