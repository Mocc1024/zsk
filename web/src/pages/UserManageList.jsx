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
  Tabs,
  Tag,
  message,
} from 'antd';
import { levelApi, userManageApi } from '../api';
import { useAuth } from '../store/AuthContext';

/**
 * 用户管理 + 等级标识
 *
 * 调整积分/经验走的是 PointService，会记流水；
 * 这里不提供「直接改余额」的入口，避免运营改了账却没有凭据。
 */
export default function UserManageList() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState('users');

  return (
    <Card>
      <Tabs
        activeKey={tab}
        onChange={setTab}
        items={[
          { key: 'users', label: '用户列表', children: <UserTable hasPermission={hasPermission} /> },
          { key: 'levels', label: '等级标识', children: <LevelConfig hasPermission={hasPermission} /> },
        ]}
      />
    </Card>
  );
}

function UserTable({ hasPermission }) {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [loading, setLoading] = useState(false);
  const [keyword, setKeyword] = useState('');
  const [status, setStatus] = useState('');
  const [acting, setActing] = useState(null);
  const [adjust, setAdjust] = useState(null);
  const [newPassword, setNewPassword] = useState(null);
  const [adjustForm] = Form.useForm();
  const [createOpen, setCreateOpen] = useState(false);
  const [createSaving, setCreateSaving] = useState(false);
  const [createForm] = Form.useForm();

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await userManageApi.list({ page, limit: pageSize, keyword, status });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, pageSize, keyword, status]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const toggleStatus = async (row) => {
    const next = Number(row.status) === 1 ? 0 : 1;
    try {
      await userManageApi.setStatus(row.id, next);
      message.success(next === 1 ? '已启用' : '已禁用');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const resetPassword = async (row) => {
    try {
      const res = await userManageApi.resetPassword(row.id);
      setNewPassword({ user: row.username, password: res.data.password, title: '密码已重置' });
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const restore = async (row) => {
    try {
      await userManageApi.restore(row.id);
      message.success('账号已恢复');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const openCreateUser = () => {
    setCreateOpen(true);
    createForm.setFieldsValue({
      username: '', nickname: '', password: '', mobile: '', email: '', status: 1,
    });
  };

  const submitCreateUser = async () => {
    const values = await createForm.validateFields();
    setCreateSaving(true);
    try {
      const res = await userManageApi.create(values);
      message.success(res.msg || '用户已创建');
      setCreateOpen(false);
      loadList();
      // 自动生成密码时必须让管理员看到并转告用户，否则没人知道初始密码是什么
      if (res.data?.password) {
        setNewPassword({ user: values.username, password: res.data.password, title: '初始密码' });
      }
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setCreateSaving(false);
    }
  };

  const openAdjust = (row) => {
    setAdjust(row);
    adjustForm.setFieldsValue({ points: 0, exp: 0 });
  };

  const submitAdjust = async () => {
    const values = await adjustForm.validateFields();
    try {
      if (values.points) {
        await userManageApi.points(adjust.id, { points: values.points, remark: values.remark });
      }
      if (values.exp) {
        await userManageApi.exp(adjust.id, { exp: values.exp });
      }
      message.success('已调整');
      setAdjust(null);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 70 },
    { title: '用户名', dataIndex: 'username' },
    { title: '昵称', dataIndex: 'nickname' },
    { title: '手机号', dataIndex: 'mobile', render: (v) => v || '-' },
    { title: '等级', dataIndex: 'level', width: 70 },
    { title: '积分', dataIndex: 'points', width: 90 },
    { title: '经验', dataIndex: 'exp', width: 90 },
    {
      title: '状态',
      dataIndex: 'status',
      width: 100,
      render: (v, row) =>
        row.deleted ? (
          <Tag color="default">已注销</Tag>
        ) : (
          <Tag color={Number(v) === 1 ? 'green' : 'red'}>{Number(v) === 1 ? '正常' : '已禁用'}</Tag>
        ),
    },
    {
      title: '操作',
      key: 'action',
      width: 300,
      fixed: 'right',
      render: (_, row) => (
        <Space size={4}>
          {hasPermission('user.status') && (
            <Button type="link" size="small" onClick={() => toggleStatus(row)}>
              {Number(row.status) === 1 ? '禁用' : '启用'}
            </Button>
          )}
          {hasPermission('user.reset-password') && (
            <Button type="link" size="small" onClick={() => resetPassword(row)}>
              重置密码
            </Button>
          )}
          {(hasPermission('user.points') || hasPermission('user.exp')) && (
            <Button type="link" size="small" onClick={() => openAdjust(row)}>
              调整积分/经验
            </Button>
          )}
          {row.deleted && hasPermission('user.update') && (
            <Button type="link" size="small" onClick={() => restore(row)}>
              恢复
            </Button>
          )}
        </Space>
      ),
    },
  ];

  return (
    <>
      <Space style={{ marginBottom: 16 }}>
        {hasPermission('user.create') && (
          <Button type="primary" onClick={openCreateUser}>
            新增用户
          </Button>
        )}
        <Input.Search
          placeholder="用户名 / 昵称 / 手机号"
          allowClear
          style={{ width: 240 }}
          onSearch={(v) => {
            setKeyword(v);
            setPage(1);
          }}
        />
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          style={{ height: 32, borderRadius: 6, border: '1px solid #d9d9d9' }}
        >
          <option value="">全部状态</option>
          <option value="1">正常</option>
          <option value="0">已禁用</option>
        </select>
      </Space>

      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        columns={columns}
        dataSource={list}
        locale={{ emptyText: <Empty description="暂无用户" /> }}
        pagination={{
          current: page,
          pageSize,
          total,
          showSizeChanger: true,
          showTotal: (t) => `共 ${t} 个用户`,
          onChange: (p, s) => {
            setPage(p);
            setPageSize(s);
          },
        }}
      />

      <Modal
        title={`调整 ${adjust?.nickname || adjust?.username} 的积分/经验`}
        open={!!adjust}
        onCancel={() => setAdjust(null)}
        onOk={submitAdjust}
        okText="提交"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={adjustForm} layout="vertical" preserve={false}>
          <Form.Item name="points" label="积分变动" extra="正数为增加，负数为扣减">
            <InputNumber style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="exp" label="经验增加" extra="经验只增不减，如需降低请直接调整等级">
            <InputNumber min={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="remark" label="备注">
            <Input placeholder="会写入积分流水，便于日后核查" maxLength={100} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title="新增用户"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        onOk={submitCreateUser}
        confirmLoading={createSaving}
        okText="创建"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={createForm} layout="vertical" preserve={false}>
          <Form.Item
            name="username"
            label="用户名"
            rules={[
              { required: true, message: '请输入用户名' },
              { pattern: /^[a-zA-Z0-9_]{4,20}$/, message: '4-20 位字母、数字或下划线' },
            ]}
          >
            <Input maxLength={20} />
          </Form.Item>
          <Form.Item name="nickname" label="昵称" extra="留空则与用户名相同">
            <Input maxLength={30} />
          </Form.Item>
          <Form.Item
            name="password"
            label="初始密码"
            extra="留空则自动生成；填写时需至少 8 位且同时包含字母和数字"
            rules={[
              {
                validator: (_, v) =>
                  !v || (v.length >= 8 && /[a-zA-Z]/.test(v) && /\d/.test(v))
                    ? Promise.resolve()
                    : Promise.reject(new Error('至少 8 位，且需同时包含字母和数字')),
              },
            ]}
          >
            <Input.Password autoComplete="new-password" />
          </Form.Item>
          <Form.Item name="mobile" label="手机号" extra="留空即可，不要填占位值">
            <Input maxLength={20} />
          </Form.Item>
          <Form.Item name="email" label="邮箱" rules={[{ type: 'email', message: '邮箱格式不正确' }]}>
            <Input maxLength={100} />
          </Form.Item>
          <Form.Item name="status" label="状态">
            <Select options={[{ value: 1, label: '正常' }, { value: 0, label: '禁用' }]} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={newPassword?.title || '密码'}
        open={!!newPassword}
        onCancel={() => setNewPassword(null)}
        footer={[
          <Button key="ok" type="primary" onClick={() => setNewPassword(null)}>
            我已记录
          </Button>,
        ]}
      >
        <p>
          用户 <strong>{newPassword?.user}</strong> 的新密码：
        </p>
        <Input value={newPassword?.password || ''} readOnly />
        <p style={{ color: '#8c8c8c', marginTop: 8 }}>关闭后无法再次查看，请妥善告知用户。</p>
      </Modal>
    </>
  );
}

function LevelConfig({ hasPermission }) {
  const [list, setList] = useState([]);
  const [loading, setLoading] = useState(false);
  const [editing, setEditing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await levelApi.list();
      setList(res.data.list || []);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const openCreate = () => {
    setEditing({});
    form.setFieldsValue({ level: '', name: '', min_exp: 0, rights_desc: '', status: 1 });
  };

  const openEdit = (row) => {
    setEditing(row);
    form.setFieldsValue(row);
  };

  const submit = async () => {
    const values = await form.validateFields();
    setSaving(true);
    try {
      if (editing?.id) {
        await levelApi.update(editing.id, values);
      } else {
        await levelApi.create(values);
      }
      message.success('已保存');
      setEditing(null);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSaving(false);
    }
  };

  const recalculate = async () => {
    try {
      const res = await levelApi.recalculate();
      message.success(res.msg || '已重算');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: '等级', dataIndex: 'level', width: 70 },
    { title: '名称', dataIndex: 'name' },
    { title: '经验门槛', dataIndex: 'min_exp', width: 100 },
    { title: '当前人数', dataIndex: 'user_count', width: 100 },
    { title: '等级说明', dataIndex: 'rights_desc' },
    {
      title: '操作',
      key: 'action',
      width: 120,
      render: (_, row) =>
        hasPermission('level.update') ? (
          <Button type="link" size="small" onClick={() => openEdit(row)}>
            编辑
          </Button>
        ) : null,
    },
  ];

  return (
    <>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="等级只是成长标记，不影响任何功能权限"
        description="收藏、评论、点赞对所有已登录用户开放，与等级无关。这里的经验门槛只决定标识何时变化。"
      />

      <Space style={{ marginBottom: 16 }}>
        {hasPermission('level.create') && (
          <Button type="primary" onClick={openCreate}>
            新增等级
          </Button>
        )}
        {hasPermission('level.update') && (
          <Popconfirm title="按当前门槛重算所有用户等级？" okText="重算" cancelText="取消" onConfirm={recalculate}>
            <Button>重算用户等级</Button>
          </Popconfirm>
        )}
      </Space>

      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        columns={columns}
        dataSource={list}
        pagination={false}
        locale={{ emptyText: <Empty description="暂无等级标识" /> }}
      />

      <Modal
        title={editing?.id ? '编辑等级' : '新增等级'}
        open={!!editing}
        onCancel={() => setEditing(null)}
        onOk={submit}
        confirmLoading={saving}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="level" label="等级" rules={[{ required: true, message: '请输入等级' }]}>
            <InputNumber min={1} style={{ width: '100%' }} disabled={!!editing?.id} />
          </Form.Item>
          <Form.Item name="name" label="名称" rules={[{ required: true, message: '请输入名称' }]}>
            <Input maxLength={30} />
          </Form.Item>
          <Form.Item name="min_exp" label="经验门槛" extra="只决定标识何时随经验变化，不是功能门槛">
            <InputNumber min={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="rights_desc" label="等级说明" extra="只写标识说明，不要写权限承诺">
            <Input.TextArea rows={2} maxLength={200} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
