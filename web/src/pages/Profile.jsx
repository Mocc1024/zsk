import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Avatar,
  Button,
  Card,
  Col,
  Descriptions,
  Form,
  Input,
  Row,
  Space,
  Tag,
  Typography,
  message,
} from 'antd';
import { UserOutlined } from '@ant-design/icons';
import { authApi } from '../api';
import { useAuth } from '../store/AuthContext';

/**
 * 个人中心：账号信息 + 基本资料 + 修改密码
 *
 * 这里只能改昵称 / 邮箱 / 头像。登录账号、所属用户组、启用状态不在此开放，
 * 否则普通管理员可以自行改组提权，那三项只能由超级管理员在「管理员管理」里改。
 */
export default function Profile() {
  const { admin, refresh, logout } = useAuth();
  const navigate = useNavigate();
  const [infoForm] = Form.useForm();
  const [pwdForm] = Form.useForm();
  const [savingInfo, setSavingInfo] = useState(false);
  const [savingPwd, setSavingPwd] = useState(false);

  // admin 是异步下发的，到位后再回填表单
  useEffect(() => {
    if (!admin) {
      return;
    }
    infoForm.setFieldsValue({
      nickname: admin.nickname || '',
      email: admin.email || '',
      avatar: admin.avatar || '',
    });
  }, [admin, infoForm]);

  const submitInfo = async () => {
    let values;
    try {
      // 校验不通过时 validateFields 会 reject，错误已显示在表单下方；
      // 这里必须接住，否则会变成控制台里的未捕获异常
      values = await infoForm.validateFields();
    } catch (e) {
      return;
    }
    setSavingInfo(true);
    try {
      await authApi.updateProfile(values);
      // 顶栏昵称/头像要立刻跟着变，否则看起来像没保存
      await refresh();
      message.success('资料已更新');
    } catch (e) {
      // 错误提示由 axios 拦截器统一弹出；这里必须接住，
      // 否则业务失败会变成未捕获的 Promise rejection 打到控制台
    } finally {
      setSavingInfo(false);
    }
  };

  const submitPassword = async () => {
    let values;
    try {
      values = await pwdForm.validateFields();
    } catch (e) {
      return;
    }
    setSavingPwd(true);
    try {
      await authApi.password({
        old_password: values.old_password,
        new_password: values.new_password,
      });
      message.success('密码修改成功，请重新登录');
      // 旧令牌此时已被后端视为失效，直接退回登录页
      logout();
      navigate('/login', { replace: true });
    } catch (e) {
      // 原密码错误、两次不一致等：提示已由拦截器弹出，这里只负责不让它冒泡
    } finally {
      setSavingPwd(false);
    }
  };

  const groupNames = admin?.group_names || [];

  return (
    <div>
      <Typography.Title level={4} style={{ marginTop: 0, marginBottom: 16 }}>
        个人中心
      </Typography.Title>

      <Row gutter={16}>
        <Col xs={24} lg={9}>
          <Card bordered={false} title="账号信息">
            <div style={{ textAlign: 'center', marginBottom: 20 }}>
              <Avatar
                size={72}
                src={admin?.avatar || undefined}
                icon={<UserOutlined />}
                style={{ backgroundColor: '#2f54eb' }}
              />
              <div style={{ marginTop: 10, fontWeight: 500, fontSize: 15 }}>
                {admin?.nickname || admin?.username}
              </div>
            </div>

            <Descriptions column={1} size="small">
              <Descriptions.Item label="登录账号">{admin?.username || '—'}</Descriptions.Item>
              <Descriptions.Item label="所属用户组">
                {groupNames.length ? (
                  <Space size={4} wrap>
                    {groupNames.map((name) => (
                      <Tag key={name} color="blue">
                        {name}
                      </Tag>
                    ))}
                  </Space>
                ) : (
                  <span style={{ color: '#999' }}>未分配</span>
                )}
              </Descriptions.Item>
              <Descriptions.Item label="状态">
                {admin?.status === 1 ? (
                  <Tag color="green">启用</Tag>
                ) : (
                  <Tag color="red">禁用</Tag>
                )}
              </Descriptions.Item>
              <Descriptions.Item label="最后登录 IP">
                {admin?.last_login_ip || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="最后登录时间">
                {admin?.last_login_time || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="创建时间">{admin?.create_time || '—'}</Descriptions.Item>
            </Descriptions>
          </Card>
        </Col>

        <Col xs={24} lg={15}>
          <Card bordered={false} title="基本资料">
            <Form form={infoForm} layout="vertical">
              <Form.Item
                label="登录账号"
                extra="登录账号不可修改，如需变更请让超级管理员在「管理员管理」中操作"
              >
                <Input value={admin?.username || ''} disabled />
              </Form.Item>
              <Form.Item
                name="nickname"
                label="昵称"
                rules={[
                  { required: true, message: '请输入昵称' },
                  { max: 50, message: '昵称不能超过 50 个字符' },
                ]}
              >
                <Input placeholder="显示名称" />
              </Form.Item>
              <Form.Item
                name="email"
                label="邮箱"
                rules={[{ type: 'email', message: '邮箱格式不正确' }]}
              >
                <Input placeholder="可选，留空表示不填" allowClear />
              </Form.Item>
              <Form.Item name="avatar" label="头像">
                <Input placeholder="图片地址，留空则显示默认头像" allowClear />
              </Form.Item>
              <Form.Item style={{ marginBottom: 0 }}>
                <Button type="primary" loading={savingInfo} onClick={submitInfo}>
                  保存资料
                </Button>
              </Form.Item>
            </Form>
          </Card>

          <Card bordered={false} title="修改密码" style={{ marginTop: 16 }}>
            <Form form={pwdForm} layout="vertical">
              <Form.Item
                name="old_password"
                label="原密码"
                rules={[{ required: true, message: '请输入原密码' }]}
              >
                <Input.Password placeholder="请输入当前使用的密码" autoComplete="current-password" />
              </Form.Item>
              <Form.Item
                name="new_password"
                label="新密码"
                rules={[
                  { required: true, message: '请输入新密码' },
                  { min: 6, message: '新密码至少 6 位' },
                ]}
              >
                <Input.Password placeholder="至少 6 位" autoComplete="new-password" />
              </Form.Item>
              <Form.Item
                name="confirm_password"
                label="确认新密码"
                dependencies={['new_password']}
                rules={[
                  { required: true, message: '请再次输入新密码' },
                  ({ getFieldValue }) => ({
                    validator(_, value) {
                      if (!value || getFieldValue('new_password') === value) {
                        return Promise.resolve();
                      }
                      return Promise.reject(new Error('两次输入的密码不一致'));
                    },
                  }),
                ]}
              >
                <Input.Password placeholder="请再次输入新密码" autoComplete="new-password" />
              </Form.Item>
              <Form.Item style={{ marginBottom: 0 }}>
                <Button type="primary" danger loading={savingPwd} onClick={submitPassword}>
                  修改密码
                </Button>
                <Typography.Text type="secondary" style={{ marginLeft: 12 }}>
                  修改成功后需要用新密码重新登录
                </Typography.Text>
              </Form.Item>
            </Form>
          </Card>
        </Col>
      </Row>
    </div>
  );
}
