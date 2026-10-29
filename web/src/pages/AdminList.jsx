import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Switch,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { EditOutlined, PlusOutlined } from '@ant-design/icons';
import { adminApi, groupApi } from '../api';
import { useAuth } from '../store/AuthContext';

/** 系统内置的初始超级管理员：不可禁用 / 删除 / 变更所属用户组 */
const BUILTIN_SUPER_ADMIN_ID = 1;

export default function AdminList() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10 });
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  // 用户组选项
  const [groups, setGroups] = useState([]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await adminApi.list(query);
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [query]);

  useEffect(() => {
    fetchList();
  }, [fetchList]);

  useEffect(() => {
    groupApi.options().then((res) => setGroups(res.data || []));
  }, []);

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ status: 1 });
    setModalOpen(true);
  };

  const openEdit = (record) => {
    setEditing(record);
    form.setFieldsValue({
      username: record.username,
      nickname: record.nickname,
      email: record.email,
      group_id: record.group_id || undefined,
      status: Number(record.status) === 1,
      password: '',
    });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      // 权限由所属用户组决定，这里只提交基本信息
      const payload = { ...values };
      if (editing) {
        await adminApi.update(editing.id, payload);
        message.success('保存成功');
      } else {
        await adminApi.create(payload);
        message.success('新增成功');
      }
      setModalOpen(false);
      fetchList();
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id) => {
    await adminApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const handleStatus = async (record) => {
    await adminApi.setStatus(record.id, record.status === 1 ? 0 : 1);
    message.success(record.status === 1 ? '已禁用' : '已启用');
    fetchList();
  };

  // 内置初始超级管理员：不可禁用、不可删除、不可变更所属用户组
  const isBuiltinAdmin = editing?.id === BUILTIN_SUPER_ADMIN_ID;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          管理员管理
        </Typography.Title>
        {hasPermission('admin.create') && (
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
            新增管理员
          </Button>
        )}
      </div>

      <Card bordered={false}>
        <Table
          rowKey="id"
          loading={loading}
          dataSource={list}
          scroll={{ x: 1130 }}
          pagination={{
            current: query.page,
            pageSize: query.limit,
            total,
            showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery({ page, limit }),
          }}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '登录账号', dataIndex: 'username', width: 140 },
            { title: '昵称', dataIndex: 'nickname', width: 140 },
            { title: '邮箱', dataIndex: 'email', ellipsis: true },
            {
              title: '所属用户组',
              dataIndex: 'group_names',
              width: 180,
              render: (v) =>
                (v || []).length ? (
                  <Space size={4} wrap>
                    {(v || []).map((name) => (
                      <Tag key={name} color="blue">
                        {name}
                      </Tag>
                    ))}
                  </Space>
                ) : (
                  <span style={{ color: '#999' }}>未分配</span>
                ),
            },
            {
              title: '状态',
              dataIndex: 'status',
              width: 90,
              render: (v) => <Tag color={v === 1 ? 'green' : 'red'}>{v === 1 ? '启用' : '禁用'}</Tag>,
            },
            { title: '最后登录IP', dataIndex: 'last_login_ip', width: 140 },
            { title: '最后登录时间', dataIndex: 'last_login_time', width: 170 },
            {
              title: '操作',
              key: 'action',
              width: 200,
              fixed: 'right',
              render: (_, record) => (
                <Space size={4}>
                  {hasPermission('admin.update') && (
                    <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>
                      编辑
                    </Button>
                  )}
                  {/* 内置初始超级管理员不可禁用、不可删除 */}
                  {hasPermission('admin.status') && record.id !== BUILTIN_SUPER_ADMIN_ID && (
                    <Button type="link" size="small" onClick={() => handleStatus(record)}>
                      {record.status === 1 ? '禁用' : '启用'}
                    </Button>
                  )}
                  {hasPermission('admin.delete') && record.id !== BUILTIN_SUPER_ADMIN_ID && (
                    <Popconfirm title="确认删除该管理员？" onConfirm={() => handleDelete(record.id)}>
                      <Button type="link" size="small" danger>
                        删除
                      </Button>
                    </Popconfirm>
                  )}
                </Space>
              ),
            },
          ]}
        />
      </Card>

      <Modal
        title={editing ? '编辑管理员' : '新增管理员'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={handleSubmit}
        confirmLoading={submitting}
        okText="保存"
        cancelText="取消"
        width={520}
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false} initialValues={{ status: 1 }}>
          <Form.Item
            name="username"
            label="登录账号"
            rules={[
              { required: true, message: '请输入登录账号' },
              { min: 3, message: '账号至少 3 个字符' },
            ]}
          >
            <Input placeholder="字母、数字或下划线" disabled={Boolean(editing)} />
          </Form.Item>
          <Form.Item
            name="password"
            label={editing ? '重置密码（留空则不修改）' : '登录密码'}
            rules={
              editing
                ? []
                : [
                    { required: true, message: '请输入登录密码' },
                    { min: 6, message: '密码至少 6 位' },
                  ]
            }
          >
            <Input.Password placeholder={editing ? '不修改请留空' : '至少 6 位'} />
          </Form.Item>
          <Form.Item name="nickname" label="昵称" rules={[{ required: true, message: '请输入昵称' }]}>
            <Input placeholder="显示名称" />
          </Form.Item>
          <Form.Item name="email" label="邮箱">
            <Input placeholder="可选" />
          </Form.Item>
          <Form.Item
            name="group_id"
            label="所属用户组"
            rules={[{ required: true, message: '请选择所属用户组' }]}
            extra={
              isBuiltinAdmin
                ? '内置初始超级管理员固定归属「超级管理员」用户组'
                : '必选，该用户的权限由其所属用户组的权限决定'
            }
          >
            <Select placeholder="请选择用户组" options={groups} disabled={isBuiltinAdmin} />
          </Form.Item>
          <Form.Item
            name="status"
            label="启用状态"
            valuePropName="checked"
            extra={isBuiltinAdmin ? '内置初始超级管理员不可禁用' : undefined}
          >
            <Switch checkedChildren="启用" unCheckedChildren="禁用" disabled={isBuiltinAdmin} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
