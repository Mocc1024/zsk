import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Alert, Button, Form, Input, Typography, message } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { authApi } from '../api';
import { useAuth } from '../store/AuthContext';
import bgImage from '../assets/login-bg.jpg';

export default function Login() {
  const navigate = useNavigate();
  const { setSession } = useAuth();
  const [loading, setLoading] = useState(false);

  const onFinish = async (values) => {
    setLoading(true);
    try {
      const res = await authApi.login(values);
      setSession(res.data.token, res.data.admin, res.data.permissions || [], res.data.menus || []);
      message.success(`欢迎回来，${res.data.admin.nickname || res.data.admin.username}`);
      navigate('/dashboard', { replace: true });
    } catch (e) {
      // 错误提示已在拦截器中统一处理
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page" style={{ backgroundImage: `url(${bgImage})` }}>
      <div className="login-card">
        <Typography.Title level={3} className="login-title">
          ZSK 内容管理后台
        </Typography.Title>
        <div className="login-subtitle">ThinkPHP 8 + React 前后端分离后台管理系统</div>

        <Form size="large" onFinish={onFinish} initialValues={{ username: 'admin', password: 'admin123' }}>
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

        <Alert
          type="info"
          showIcon
          message="演示账号"
          description={
            <div style={{ fontSize: 12, lineHeight: 1.9 }}>
              <div>超级管理员：admin / admin123（全部权限）</div>
              <div>内容编辑：editor / editor123（内容 + 分类权限）</div>
            </div>
          }
        />
      </div>
    </div>
  );
}
