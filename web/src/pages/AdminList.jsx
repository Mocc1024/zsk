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
  Tabs,
  Tag,
  Typography,
  message,
} from 'antd';
import { EditOutlined, PlusOutlined } from '@ant-design/icons';
import { adminApi, permissionApi } from '../api';
import PermissionTree from '../components/PermissionTree';
import { useAuth } from '../store/AuthContext';
import { ROLE_OPTIONS, roleLabel } from '../utils/constants';

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

  // 权限树数据
  const [permTree, setPermTree] = useState([]);
  const [permKeys, setPermKeys] = useState([]);
  const [checkedKeys, setCheckedKeys] = useState([]);

  // 当前编辑对象的角色：超管默认拥有全部权限，勾选框置灰
  const watchedRole = Form.useWatch('role', form);
  const currentRole = editing ? editing.role : watchedRole;
  const isSuperTarget = currentRole === 'admin';

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

  // 权限树只在第一次打开弹窗时请求一次
  const loadPermTree = useCallback(async () => {
    if (permTree.length > 0) {
      return;
    }
    const res = await permissionApi.tree();
    setPermTree(res.data.tree || []);
    setPermKeys(res.data.allKeys || []);
  }, [permTree]);

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ role: 'editor', status: 1 });
    setCheckedKeys([]);
    setModalOpen(true);
    loadPermTree();
  };

  const openEdit = (record) => {
    setEditing(record);
    form.setFieldsValue({
      username: record.username,
      nickname: record.nickname,
      email: record.email,
      role: record.role,
      status: Number(record.status) === 1,
      password: '',
    });
    // 后端已按角色算好：超管返回全部 key，其余返回已分配的
    setCheckedKeys(record.permissions || []);
    setModalOpen(true);
    loadPermTree();
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      const payload = {
        ...values,
        // 超级管理员的权限由后端直接置 NULL，这里传空数组即可
        permissions: isSuperTarget ? [] : checkedKeys,
      };
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
            {
              title: '角色',
              dataIndex: 'role',
              width: 120,
              render: (v) => <Tag color={v === 'admin' ? 'blue' : 'green'}>{roleLabel(v)}</Tag>,
            },
            { title: '邮箱', dataIndex: 'email', ellipsis: true },
            {
              title: '权限',
              dataIndex: 'permissions',
              width: 110,
              render: (v, record) =>
                record.role === 'admin' ? (
                  <Tag color="blue">全部权限</Tag>
                ) : (
                  <span>{(v || []).length} 项</span>
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
              width: 180,
              render: (_, record) => (
                <Space size={4}>
                  {hasPermission('admin.update') && (
                    <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>
                      编辑
                    </Button>
                  )}
                  {hasPermission('admin.status') && (
                    <Button type="link" size="small" onClick={() => handleStatus(record)}>
                      {record.status === 1 ? '禁用' : '启用'}
                    </Button>
                  )}
                  {hasPermission('admin.delete') && (
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
        width={720}
        destroyOnClose
      >
        <Tabs
          items={[
            {
              key: 'base',
              label: '基本信息',
              children: (
                <Form
                  form={form}
                  layout="vertical"
                  preserve={false}
                  initialValues={{ role: 'editor', status: 1 }}
                >
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
                  <Form.Item name="role" label="角色" rules={[{ required: true, message: '请选择角色' }]}>
                    <Select options={ROLE_OPTIONS} />
                  </Form.Item>
                  <Form.Item name="status" label="启用状态" valuePropName="checked">
                    <Switch checkedChildren="启用" unCheckedChildren="禁用" />
                  </Form.Item>
                </Form>
              ),
            },
            {
              key: 'perm',
              label: '权限配置',
              children: (
                <PermissionTree
                  tree={permTree}
                  allKeys={permKeys}
                  value={isSuperTarget ? permKeys : checkedKeys}
                  onChange={setCheckedKeys}
                  disabled={isSuperTarget}
                />
              ),
            },
          ]}
        />
      </Modal>
    </div>
  );
}
