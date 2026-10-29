import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  InputNumber,
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
import { ruleApi } from '../api';
import { useAuth } from '../store/AuthContext';

const TYPE_OPTIONS = [
  { label: '菜单', value: 1 },
  { label: '权限点', value: 2 },
];

const ICON_OPTIONS = [
  'AppstoreOutlined',
  'DashboardOutlined',
  'FileTextOutlined',
  'HistoryOutlined',
  'MenuOutlined',
  'SafetyCertificateOutlined',
  'SettingOutlined',
  'TeamOutlined',
  'UserOutlined',
].map((value) => ({ label: value, value }));

export default function PermissionList() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [tree, setTree] = useState([]);
  const [loading, setLoading] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  // scope=manage：管理端需要看到已禁用的项，否则禁用后就无法再启用
  const fetchTree = useCallback(async () => {
    setLoading(true);
    try {
      const res = await ruleApi.tree({ scope: 'manage' });
      setTree(res.data.list || []);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchTree();
  }, [fetchTree]);

  /** 上级只能选菜单，拼成层级名称 */
  const parentOptions = useMemo(() => {
    const options = [{ label: '顶级菜单', value: 0 }];
    const walk = (nodes, prefix) => {
      nodes.forEach((node) => {
        if (Number(node.type) !== 1) {
          return;
        }
        const label = prefix ? `${prefix} / ${node.title}` : node.title;
        options.push({ label, value: Number(node.id) });
        if (node.children?.length) {
          walk(node.children, label);
        }
      });
    };
    walk(tree, '');
    return options;
  }, [tree]);

  // 搜索关键词、状态筛选、展开的父节点
  const [keyword, setKeyword] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [expandedKeys, setExpandedKeys] = useState([]);

  /** 所有带子节点的行 key，用于首屏全部展开以及筛选时强制展开 */
  const allParentKeys = useMemo(() => {
    const collect = (nodes) =>
      nodes.reduce((acc, n) => {
        if (n.children?.length) {
          acc.push(n.id);
          acc.push(...collect(n.children));
        }
        return acc;
      }, []);
    return collect(tree);
  }, [tree]);

  // 数据加载后默认展开全部
  useEffect(() => {
    if (allParentKeys.length > 0) {
      setExpandedKeys((prev) => (prev.length ? prev : allParentKeys));
    }
  }, [allParentKeys]);

  /**
   * 按名称 / 标识 / 路由 + 状态过滤树。
   * 自身命中即保留，并始终带上其父路径，避免结果丢失层级上下文。
   */
  const displayTree = useMemo(() => {
    const kw = keyword.trim().toLowerCase();
    const walk = (nodes) =>
      nodes.reduce((acc, n) => {
        const children = n.children?.length ? walk(n.children) : undefined;
        const hit =
          kw === '' || [n.title, n.name, n.path].some((v) => String(v ?? '').toLowerCase().includes(kw));
        const statusOk = statusFilter === '' || Number(n.status) === Number(statusFilter);
        if (hit && (statusOk || (children && children.length))) {
          acc.push({ ...n, children });
        }
        return acc;
      }, []);
    return walk(tree);
  }, [tree, keyword, statusFilter]);

  const hasFilter = keyword.trim() !== '' || statusFilter !== '';

  const resetFilter = () => {
    setKeyword('');
    setStatusFilter('');
  };

  const openCreate = (pid = 0) => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ pid, type: 1, sort: 0, status: true });
    setModalOpen(true);
  };

  const openEdit = (record) => {
    setEditing(record);
    form.setFieldsValue({
      pid: Number(record.pid) || 0,
      type: Number(record.type) || 1,
      title: record.title,
      name: record.name,
      path: record.path,
      icon: record.icon,
      sort: Number(record.sort) || 0,
      status: Number(record.status) === 1,
    });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    const payload = {
      ...values,
      sort: Number(values.sort) || 0,
      status: values.status === false || values.status === 0 ? 0 : 1,
    };
    setSubmitting(true);
    try {
      if (editing) {
        await ruleApi.update(editing.id, payload);
        message.success('保存成功');
      } else {
        await ruleApi.create(payload);
        message.success('新增成功');
      }
      setModalOpen(false);
      fetchTree();
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id) => {
    await ruleApi.remove(id);
    message.success('删除成功');
    fetchTree();
  };

  const handleStatus = async (record) => {
    await ruleApi.setStatus(record.id, record.status === 1 ? 0 : 1);
    message.success(record.status === 1 ? '已禁用' : '已启用');
    fetchTree();
  };

  const columns = useMemo(
    () => [
      { title: 'ID', dataIndex: 'id', width: 70 },
      { title: '名称', dataIndex: 'title', width: 180 },
      {
        title: '类型',
        dataIndex: 'type',
        width: 90,
        render: (v) =>
          Number(v) === 1 ? <Tag color="blue">菜单</Tag> : <Tag color="purple">权限点</Tag>,
      },
      {
        title: '权限标识',
        dataIndex: 'name',
        width: 170,
        render: (v) => (v ? <span style={{ fontFamily: 'monospace' }}>{v}</span> : '-'),
      },
      {
        title: '前端路由',
        dataIndex: 'path',
        width: 130,
        render: (v) => (v ? <span style={{ fontFamily: 'monospace' }}>{v}</span> : '-'),
      },
      { title: '图标', dataIndex: 'icon', width: 140, render: (v) => v || '-' },
      { title: '排序', dataIndex: 'sort', width: 70 },
      {
        title: '状态',
        dataIndex: 'status',
        width: 90,
        render: (v) => <Tag color={Number(v) === 1 ? 'green' : 'red'}>{Number(v) === 1 ? '启用' : '禁用'}</Tag>,
      },
      {
        title: '操作',
        key: 'action',
        // 最多 4 个按钮约需 220px，再加单元格左右各 16px 内边距
        width: 260,
        fixed: 'right',
        render: (_, record) => (
          <Space size={0} style={{ flexWrap: 'nowrap' }}>
            {hasPermission('permission.create') && Number(record.type) === 1 && (
              <Button type="link" size="small" onClick={() => openCreate(record.id)}>
                新增子级
              </Button>
            )}
            {hasPermission('permission.update') && (
              <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>
                编辑
              </Button>
            )}
            {hasPermission('permission.status') && (
              <Button type="link" size="small" onClick={() => handleStatus(record)}>
                {record.status === 1 ? '禁用' : '启用'}
              </Button>
            )}
            {hasPermission('permission.delete') && (
              <Popconfirm title="删除会同时删除下级节点，确认删除？" onConfirm={() => handleDelete(record.id)}>
                <Button type="link" size="small" danger>
                  删除
                </Button>
              </Popconfirm>
            )}
          </Space>
        ),
      },
    ],
    [hasPermission]
  );

  return (
    <div>
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: 12,
          flexWrap: 'wrap',
          marginBottom: 16,
        }}
      >
        <Typography.Title level={4} style={{ margin: 0 }}>
          权限菜单
        </Typography.Title>
        <Space wrap>
          <Input
            allowClear
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            placeholder="搜索名称 / 标识 / 路由"
            style={{ width: 220 }}
          />
          <Select
            allowClear
            value={statusFilter || undefined}
            onChange={(v) => setStatusFilter(v ?? '')}
            placeholder="状态筛选"
            style={{ width: 120 }}
            options={[
              { label: '启用', value: 1 },
              { label: '禁用', value: 0 },
            ]}
          />
          {hasFilter && <Button onClick={resetFilter}>重置</Button>}
          {hasPermission('permission.create') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => openCreate(0)}>
              新增菜单
            </Button>
          )}
        </Space>
      </div>

      <Card bordered={false}>
        <Table
          rowKey="id"
          loading={loading}
          dataSource={displayTree}
          columns={columns}
          scroll={{ x: 1200 }}
          pagination={false}
          expandable={{
            // 筛选时强制展开，否则命中项藏在折叠的子树里看不见
            expandedRowKeys: hasFilter ? allParentKeys : expandedKeys,
            onExpandedRowsChange: setExpandedKeys,
          }}
          rowClassName={(record) => (Number(record.status) === 1 ? '' : 'row-disabled')}
          locale={{
            emptyText: hasFilter ? '没有匹配的权限项，试试换个关键词或重置筛选' : '暂无数据',
          }}
        />
      </Card>

      <Modal
        title={editing ? '编辑权限菜单' : '新增权限菜单'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={handleSubmit}
        confirmLoading={submitting}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form
          form={form}
          layout="vertical"
          preserve={false}
          initialValues={{ pid: 0, type: 1, sort: 0, status: true }}
        >
          <Form.Item name="pid" label="上级" rules={[{ required: true, message: '请选择上级' }]}>
            <Select options={parentOptions} placeholder="顶级菜单或选择上级菜单" />
          </Form.Item>
          <Form.Item name="type" label="类型" rules={[{ required: true, message: '请选择类型' }]}>
            <Select options={TYPE_OPTIONS} />
          </Form.Item>
          <Form.Item name="title" label="名称" rules={[{ required: true, message: '请输入名称' }]}>
            <Input placeholder="例如：内容管理" maxLength={60} />
          </Form.Item>
          <Form.Item
            name="name"
            label="权限标识"
            extra="菜单可留空；权限点必填，如 content.list"
          >
            <Input placeholder="例如：content.list" maxLength={100} />
          </Form.Item>
          <Form.Item name="path" label="前端路由">
            <Input placeholder="例如：/content" maxLength={150} />
          </Form.Item>
          <Form.Item name="icon" label="图标">
            <Select allowClear placeholder="可选" options={ICON_OPTIONS} />
          </Form.Item>
          <Form.Item name="sort" label="排序值">
            <InputNumber min={0} max={9999} />
          </Form.Item>
          <Form.Item name="status" label="启用状态" valuePropName="checked">
            <Switch checkedChildren="启用" unCheckedChildren="禁用" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
