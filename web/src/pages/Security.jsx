import { useEffect, useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Col,
  Form,
  Input,
  Modal,
  Row,
  Space,
  Tag,
  Typography,
  message,
} from 'antd';
import { MobileOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import QRCode from 'qrcode';
import { authApi } from '../api';

/**
 * 安全设置：两步验证（TOTP）
 *
 * 前端只负责展示密钥二维码和收集动态码，密钥的生成、校验、恢复码全在后端，
 * 密钥本身从不回显到列表或 profile 接口里。
 */
export default function Security() {
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(false);

  // 绑定流程
  const [bindOpen, setBindOpen] = useState(false);
  const [setup, setSetup] = useState(null);
  const [qrImage, setQrImage] = useState('');
  const [bindForm] = Form.useForm();

  // 关闭流程
  const [disableOpen, setDisableOpen] = useState(false);
  const [disableForm] = Form.useForm();

  // 恢复码（启用成功后只显示这一次）
  const [recoveryCodes, setRecoveryCodes] = useState([]);

  const fetchStatus = async () => {
    const res = await authApi.totpStatus();
    setStatus(res.data);
  };

  useEffect(() => {
    fetchStatus();
  }, []);

  const startBind = async () => {
    setLoading(true);
    try {
      const res = await authApi.totpSetup();
      setSetup(res.data);
      // 二维码内容就是 otpauth:// 链接，验证器 App 扫码后自行保存密钥
      const image = await QRCode.toDataURL(res.data.uri, { width: 190, margin: 1 });
      setQrImage(image);
      bindForm.resetFields();
      setBindOpen(true);
    } catch (e) {
      // 提示已由拦截器处理
    } finally {
      setLoading(false);
    }
  };

  const submitBind = async (values) => {
    setLoading(true);
    try {
      const res = await authApi.totpEnable({ code: values.code });
      setBindOpen(false);
      setRecoveryCodes(res.data?.recovery_codes || []);
      await fetchStatus();
      message.success('两步验证已启用');
    } catch (e) {
      // 错误码（比如验证码不对）已提示，弹窗保持打开让用户重试
    } finally {
      setLoading(false);
    }
  };

  const submitDisable = async (values) => {
    setLoading(true);
    try {
      await authApi.totpDisable(values);
      setDisableOpen(false);
      disableForm.resetFields();
      await fetchStatus();
      message.success('两步验证已关闭');
    } catch (e) {
      // 同上
    } finally {
      setLoading(false);
    }
  };

  const enabled = status?.enabled === true;

  return (
    <div>
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={14}>
          <Card
            bordered={false}
            title={
              <Space>
                <SafetyCertificateOutlined />
                <span>两步验证（TOTP）</span>
                {status && (
                  <Tag color={enabled ? 'green' : 'default'}>{enabled ? '已启用' : '未启用'}</Tag>
                )}
              </Space>
            }
          >
            <Typography.Paragraph type="secondary" style={{ marginBottom: 20 }}>
              开启后，登录需要在密码之外再输入手机验证器上的 6 位动态码。
              动态码每 30 秒变化一次，不联网也能生成——即使密码泄漏，对方没有你的手机也进不来。
            </Typography.Paragraph>

            {enabled ? (
              <Space direction="vertical" size={16} style={{ width: '100%' }}>
                <Alert
                  type="success"
                  showIcon
                  message="当前账号已受两步验证保护"
                  description={
                    <span>
                      剩余恢复码 <b>{status.recovery_left ?? 0}</b> 个。
                      手机丢失时可使用恢复码登录，用掉一个少一个。
                    </span>
                  }
                />
                <Space>
                  <Button danger onClick={() => { disableForm.resetFields(); setDisableOpen(true); }}>
                    关闭两步验证
                  </Button>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    关闭需要再次验证登录密码和当前动态码
                  </Typography.Text>
                </Space>
              </Space>
            ) : (
              <Space direction="vertical" size={16} style={{ width: '100%' }}>
                <Alert
                  type="warning"
                  showIcon
                  message="建议开启"
                  description="这个后台能在公网访问，一旦密码泄漏，没有第二道防线。"
                />
                <Button type="primary" loading={loading} onClick={startBind}>
                  开始绑定
                </Button>
              </Space>
            )}
          </Card>
        </Col>

        <Col xs={24} lg={10}>
          <Card bordered={false} title={<Space><MobileOutlined />支持哪些验证器</Space>}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
              任何支持 TOTP 标准的 App 都可以，扫码后会自动出现「ZSK」条目：
            </Typography.Paragraph>
            <ul style={{ paddingLeft: 18, lineHeight: 2, marginBottom: 0 }}>
              <li>iPhone：系统自带「密码」App（iOS 15+），无需安装</li>
              <li>Android：Microsoft Authenticator、Aegis、FreeOTP</li>
              <li>通用：1Password、Bitwarden 等密码管理器</li>
            </ul>
          </Card>
        </Col>
      </Row>

      {/* 绑定：二维码 + 验证码 */}
      <Modal
        open={bindOpen}
        title="绑定验证器"
        onCancel={() => setBindOpen(false)}
        footer={null}
        destroyOnClose
        width={460}
      >
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
          <Typography.Text type="secondary">
            1. 用手机上的验证器 App 扫描下面的二维码
          </Typography.Text>

          <div style={{ textAlign: 'center' }}>
            {qrImage ? (
              <img src={qrImage} alt="TOTP 二维码" style={{ width: 190, height: 190 }} />
            ) : (
              <div style={{ height: 190 }} />
            )}
          </div>

          <div>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              扫不了？在 App 里手动输入这串密钥：
            </Typography.Text>
            <Typography.Paragraph
              copyable
              style={{
                fontFamily: 'Consolas, monospace',
                background: '#fafafa',
                padding: '6px 10px',
                borderRadius: 4,
                wordBreak: 'break-all',
                marginBottom: 0,
              }}
            >
              {setup?.secret}
            </Typography.Paragraph>
          </div>

          <Typography.Text type="secondary">
            2. 输入 App 上显示的 6 位数字，确认绑定
          </Typography.Text>

          <Form form={bindForm} onFinish={submitBind}>
            <Form.Item
              name="code"
              rules={[
                { required: true, message: '请输入 6 位验证码' },
                { pattern: /^\d{6}$/, message: '验证码是 6 位数字' },
              ]}
              style={{ marginBottom: 12 }}
            >
              <Input
                placeholder="6 位数字"
                maxLength={6}
                autoFocus
                inputMode="numeric"
                style={{ letterSpacing: 6, fontSize: 18, textAlign: 'center' }}
              />
            </Form.Item>
            <Button type="primary" htmlType="submit" block loading={loading}>
              确认启用
            </Button>
          </Form>
        </Space>
      </Modal>

      {/* 恢复码：只显示这一次 */}
      <Modal
        open={recoveryCodes.length > 0}
        title="请保存恢复码"
        closable={false}
        maskClosable={false}
        footer={
          <Button type="primary" onClick={() => setRecoveryCodes([])}>
            我已妥善保存
          </Button>
        }
        width={440}
      >
        <Alert
          type="warning"
          showIcon
          message="这些码只显示这一次"
          description="手机丢失或换设备时，用其中任意一个可以登录。每个只能用一次，请抄到密码管理器或打印保存。"
          style={{ marginBottom: 16 }}
        />
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: '1fr 1fr',
            gap: 8,
            fontFamily: 'Consolas, monospace',
            fontSize: 15,
          }}
        >
          {recoveryCodes.map((code) => (
            <div key={code} style={{ background: '#fafafa', padding: '6px 10px', borderRadius: 4 }}>
              {code}
            </div>
          ))}
        </div>
      </Modal>

      {/* 关闭：密码 + 当前动态码 */}
      <Modal
        open={disableOpen}
        title="关闭两步验证"
        onCancel={() => setDisableOpen(false)}
        onOk={() => disableForm.submit()}
        confirmLoading={loading}
        okText="确认关闭"
        okButtonProps={{ danger: true }}
        destroyOnClose
      >
        <Alert
          type="warning"
          showIcon
          message="关闭后仅凭密码即可登录"
          style={{ marginBottom: 16 }}
        />
        <Form form={disableForm} layout="vertical" onFinish={submitDisable}>
          <Form.Item name="password" label="登录密码" rules={[{ required: true, message: '请输入登录密码' }]}>
            <Input.Password placeholder="当前账号的登录密码" autoComplete="current-password" />
          </Form.Item>
          <Form.Item
            name="code"
            label="当前动态码"
            rules={[
              { required: true, message: '请输入验证码' },
              { pattern: /^\d{6}$/, message: '验证码是 6 位数字' },
            ]}
          >
            <Input placeholder="验证器上的 6 位数字" maxLength={6} inputMode="numeric" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
