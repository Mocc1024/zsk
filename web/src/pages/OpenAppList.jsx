import { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { openAppApi } from '../api';
import { useAuth } from '../store/AuthContext';
import { ENABLE_OPTIONS } from '../utils/constants';

/**
 * 开放应用管理
 *
 * 密钥只在「新建 / 轮换」的响应里返回一次明文，之后列表只显示脱敏值。
 * 所以这两个操作必须用弹窗把明文显著地展示出来并支持复制——
 * 关掉就再也拿不到了，只能再轮换一次。
 */
export default function OpenAppList() {
  const { hasPermission } = useAuth();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [loading, setLoading] = useState(false);
  const [editing, setEditing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [secretTip, setSecretTip] = useState(null);
  const [form] = Form.useForm();

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await openAppApi.list({ page, limit: pageSize });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, pageSize]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const openCreate = () => {
    setEditing({});
    form.setFieldsValue({ name: '', allow_paths: '*', qps: 10, status: 1 });
  };

  const openEdit = (row) => {
    setEditing(row);
    form.setFieldsValue({
      name: row.name,
      allow_paths: row.allow_paths,
      qps: row.qps,
    });
  };

  const handleSave = async () => {
    const values = await form.validateFields();
    setSaving(true);
    try {
      const res = editing?.id
        ? await openAppApi.update(editing.id, values)
        : await openAppApi.create(values);

      message.success(editing?.id ? '已保存' : '应用已创建');
      setEditing(null);

      // 新建时后端返回明文密钥，必须立刻展示
      if (!editing?.id && res?.data?.app_secret) {
        setSecretTip({
          title: '请保存应用密钥',
          appKey: res.data.app_key,
          secret: res.data.app_secret,
        });
      }
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSaving(false);
    }
  };

  const handleStatus = async (row) => {
    const next = Number(row.status) === 1 ? 0 : 1;
    try {
      await openAppApi.setStatus(row.id, next);
      message.success(next === 1 ? '已启用' : '已停用');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const handleRotate = async (row) => {
    try {
      const res = await openAppApi.rotate(row.id);
      setSecretTip({
        title: '密钥已轮换，旧签名立即失效',
        appKey: res.data.app_key,
        secret: res.data.app_secret,
      });
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: '应用名称', dataIndex: 'name' },
    { title: 'AppKey', dataIndex: 'app_key', render: (v) => <Typography.Text code>{v}</Typography.Text> },
    {
      title: 'AppSecret',
      dataIndex: 'app_secret',
      render: (v) => <Typography.Text type="secondary">{v || '-'}</Typography.Text>,
    },
    {
      title: '允许路径',
      dataIndex: 'allow_paths',
      render: (v) => <Typography.Text type="secondary">{v || '*'}</Typography.Text>,
    },
    { title: 'QPS', dataIndex: 'qps', width: 80 },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (status) => (
        <Tag color={Number(status) === 1 ? 'green' : 'red'}>
          {Number(status) === 1 ? '启用' : '停用'}
        </Tag>
      ),
    },
    { title: '最近调用', dataIndex: 'last_used_time', width: 180, render: (t) => t || '未调用过' },
    {
      title: '操作',
      key: 'action',
      width: 220,
      fixed: 'right',
      render: (_, row) => (
        <Space size={4}>
          {hasPermission('open.update') && (
            <Button type="link" size="small" onClick={() => openEdit(row)}>
              编辑
            </Button>
          )}
          {hasPermission('open.status') && (
            <Button type="link" size="small" onClick={() => handleStatus(row)}>
              {Number(row.status) === 1 ? '停用' : '启用'}
            </Button>
          )}
          {hasPermission('open.rotate') && (
            <Popconfirm
              title="确认轮换该应用的密钥？"
              description="轮换后旧签名立即失效，调用方需同步更新"
              okText="确认轮换"
              cancelText="取消"
              onConfirm={() => handleRotate(row)}
            >
              <Button type="link" size="small" danger>
                轮换密钥
              </Button>
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  return (
    <>
      <Card
        title="开放应用"
        extra={
          hasPermission('open.create') && (
            <Button type="primary" onClick={openCreate}>
              新增应用
            </Button>
          )
        }
      >
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 16 }}
          message="应用停用后，其调用会立即被拒绝；删除不提供，停用即可保留历史调用记录"
        />
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={list}
          locale={{ emptyText: <Empty description="还没有开放应用" /> }}
          pagination={{
            current: page,
            pageSize,
            total,
            showSizeChanger: true,
            showTotal: (t) => `共 ${t} 个应用`,
            onChange: (p, s) => {
              setPage(p);
              setPageSize(s);
            },
          }}
        />
      </Card>

      <Modal
        title={editing?.id ? '编辑应用' : '新增应用'}
        open={!!editing}
        onCancel={() => setEditing(null)}
        onOk={handleSave}
        confirmLoading={saving}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="name" label="应用名称" rules={[{ required: true, message: '请输入应用名称' }]}>
            <Input placeholder="如：官网内容同步" maxLength={50} />
          </Form.Item>
          <Form.Item
            name="allow_paths"
            label="允许访问的路径"
            extra="多个用英文逗号分隔，填 * 表示允许全部"
          >
            <Input placeholder="*" maxLength={500} />
          </Form.Item>
          <Form.Item name="qps" label="每秒请求上限">
            <InputNumber min={1} max={1000} style={{ width: '100%' }} />
          </Form.Item>
          {!editing?.id && (
            <Form.Item name="status" label="状态">
              <Select options={ENABLE_OPTIONS} />
            </Form.Item>
          )}
        </Form>
      </Modal>

      <Modal
        title={secretTip?.title}
        open={!!secretTip}
        onCancel={() => setSecretTip(null)}
        footer={[
          <Button key="close" type="primary" onClick={() => setSecretTip(null)}>
            我已保存
          </Button>,
        ]}
      >
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 12 }}
          message="密钥仅显示这一次，关闭后无法再次查看"
        />
        <p style={{ marginBottom: 4 }}>AppKey</p>
        <Typography.Paragraph copyable>
          <Typography.Text code>{secretTip?.appKey}</Typography.Text>
        </Typography.Paragraph>
        <p style={{ marginBottom: 4 }}>AppSecret</p>
        <Typography.Paragraph copyable>
          <Typography.Text code>{secretTip?.secret}</Typography.Text>
        </Typography.Paragraph>
      </Modal>
    </>
  );
}
