import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Checkbox,
  Divider,
  Drawer,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Space,
  Switch,
  Table,
  Tag,
  Tree,
  Typography,
  message,
} from 'antd';
import { EditOutlined, KeyOutlined, PlusOutlined } from '@ant-design/icons';
import { groupApi, ruleApi } from '../api';
import { useAuth } from '../store/AuthContext';

/** 系统内置的超级管理员组：默认拥有全部权限，不可改权限 / 禁用 / 删除 */
const BUILTIN_SUPER_GROUP_ID = 1;

/** 后端规则树转 antd Tree 数据 */
const toTreeData = (nodes = []) =>
  nodes.map((node) => ({
    key: node.id,
    title: node.name ? `${node.title}（${node.name}）` : node.title,
    children: node.children?.length ? toTreeData(node.children) : undefined,
  }));

export default function GroupList() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [searchForm] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10 });
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  // 权限分配弹窗
  const [authOpen, setAuthOpen] = useState(false);
  const [authGroup, setAuthGroup] = useState(null);
  const [treeData, setTreeData] = useState([]);
  const [checkedKeys, setCheckedKeys] = useState([]);
  const [isAll, setIsAll] = useState(false);
  const [authSubmitting, setAuthSubmitting] = useState(false);
  const [keyword, setKeyword] = useState('');

  // 叶子权限点的 key 集合，用于「全选」
  const allLeafKeys = useMemo(() => {
    const collect = (nodes) => nodes.flatMap((n) => (n.children?.length ? collect(n.children) : [n.key]));
    return collect(treeData);
  }, [treeData]);

  // 按名称 / 标识过滤权限树，父节点命中或无子树时保留父节点
  const filteredTree = useMemo(() => {
    const kw = keyword.trim().toLowerCase();
    if (!kw) {
      return treeData;
    }
    const walk = (nodes) =>
      nodes.reduce((acc, node) => {
        const children = node.children?.length ? walk(node.children) : undefined;
        const hit = String(node.title).toLowerCase().includes(kw);
        if (hit || (children && children.length)) {
          acc.push({ ...node, children });
        }
        return acc;
      }, []);
    return walk(treeData);
  }, [treeData, keyword]);

  const toggleAll = () => {
    setIsAll((prev) => {
      const next = !prev;
      if (next) {
        setCheckedKeys([]);
      }
      return next;
    });
  };

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await groupApi.list(query);
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [query]);

  useEffect(() => {
    fetchList();
  }, [fetchList]);

  const handleSearch = (values) => {
    setQuery((prev) => ({
      ...prev,
      page: 1,
      keyword: values.keyword || undefined,
      status: values.status,
    }));
  };

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ sort: 0, status: true });
    setModalOpen(true);
  };

  const openEdit = (record) => {
    setEditing(record);
    form.setFieldsValue({
      title: record.title,
      description: record.description,
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
        await groupApi.update(editing.id, payload);
        message.success('保存成功');
      } else {
        await groupApi.create(payload);
        message.success('新增成功');
      }
      setModalOpen(false);
      fetchList();
    } finally {
      setSubmitting(false);
    }
  };

  const handleStatus = async (record) => {
    await groupApi.setStatus(record.id, record.status === 1 ? 0 : 1);
    message.success(record.status === 1 ? '已禁用' : '已启用');
    fetchList();
  };

  const handleDelete = async (id) => {
    await groupApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const openAuthorize = async (record) => {
    setAuthGroup(record);
    setKeyword('');
    try {
      if (treeData.length === 0) {
        const res = await ruleApi.tree();
        setTreeData(toTreeData(res.data.list || []));
      }
      const rules = await groupApi.rules(record.id);
      setIsAll(Boolean(rules.data.is_all));
      setCheckedKeys(rules.data.rule_ids || []);
      setAuthOpen(true);
    } catch {
      message.error('加载权限数据失败');
    }
  };

  const handleAuthorize = async () => {
    setAuthSubmitting(true);
    try {
      await groupApi.authorize(authGroup.id, checkedKeys);
      message.success('权限分配成功');
      setAuthOpen(false);
      fetchList();
    } finally {
      setAuthSubmitting(false);
    }
  };

  const columns = useMemo(
    () => [
      { title: 'ID', dataIndex: 'id', width: 70 },
      { title: '用户组名称', dataIndex: 'title', width: 140 },
      { title: '描述', dataIndex: 'description', ellipsis: true, responsive: ['lg'] },
      {
        title: '权限',
        dataIndex: 'rules',
        width: 90,
        render: (v) =>
          String(v ?? '') === '*' ? <Tag color="blue">全部权限</Tag> : <span>{(v ? String(v).split(',').filter(Boolean).length : 0)} 项</span>,
      },
      { title: '成员数', dataIndex: 'member_count', width: 80, responsive: ['xl'] },
      { title: '排序', dataIndex: 'sort', width: 70, responsive: ['xl'] },
      {
        title: '状态',
        dataIndex: 'status',
        width: 80,
        render: (v) => <Tag color={Number(v) === 1 ? 'green' : 'red'}>{Number(v) === 1 ? '启用' : '禁用'}</Tag>,
      },
      { title: '创建时间', dataIndex: 'create_time', width: 150, responsive: ['xl'] },
      {
        title: '操作',
        key: 'action',
        // 4 个按钮约需 250px，再加单元格左右各 16px 内边距
        width: 290,
        fixed: 'right',
        render: (_, record) => (
          <Space size={0} style={{ flexWrap: 'nowrap' }}>
            {hasPermission('group.update') && (
              <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>
                编辑
              </Button>
            )}
            {/* 内置超级管理员组默认拥有全部权限，无需也无法再分配 */}
            {hasPermission('group.authorize') && record.id !== BUILTIN_SUPER_GROUP_ID && (
              <Button type="link" size="small" icon={<KeyOutlined />} onClick={() => openAuthorize(record)}>
                分配权限
              </Button>
            )}
            {hasPermission('group.status') && record.id !== BUILTIN_SUPER_GROUP_ID && (
              <Button type="link" size="small" onClick={() => handleStatus(record)}>
                {record.status === 1 ? '禁用' : '启用'}
              </Button>
            )}
            {hasPermission('group.delete') && record.id !== BUILTIN_SUPER_GROUP_ID && (
              <Popconfirm title="确认删除该用户组？" onConfirm={() => handleDelete(record.id)}>
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
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          用户组管理
        </Typography.Title>
        {hasPermission('group.create') && (
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
            新建用户组
          </Button>
        )}
      </div>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={searchForm} layout="inline" onFinish={handleSearch} style={{ rowGap: 12 }}>
          <Form.Item name="keyword">
            <Input allowClear placeholder="用户组名称 / 描述" style={{ width: 200 }} />
          </Form.Item>
          <Form.Item name="status">
            <Space>
              <Button type="primary" htmlType="submit">
                查询
              </Button>
              <Button
                onClick={() => {
                  searchForm.resetFields();
                  setQuery({ page: 1, limit: query.limit });
                }}
              >
                重置
              </Button>
            </Space>
          </Form.Item>
        </Form>
      </Card>

      <Card bordered={false}>
        <Table
          rowKey="id"
          loading={loading}
          dataSource={list}
          columns={columns}
          scroll={{ x: 670 }}
          pagination={{
            current: query.page,
            pageSize: query.limit,
            total,
            showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery({ page, limit }),
          }}
        />
      </Card>

      {/* 新增 / 编辑 */}
      <Modal
        title={editing ? '编辑用户组' : '新建用户组'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={handleSubmit}
        confirmLoading={submitting}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false} initialValues={{ sort: 0, status: true }}>
          <Form.Item name="title" label="用户组名称" rules={[{ required: true, message: '请输入用户组名称' }]}>
            <Input placeholder="例如：内容运营" maxLength={60} />
          </Form.Item>
          <Form.Item name="description" label="描述">
            <Input.TextArea rows={2} maxLength={255} placeholder="该用户组的职责说明" />
          </Form.Item>
          <Form.Item name="sort" label="排序值">
            <InputNumber min={0} max={9999} />
          </Form.Item>
          <Form.Item name="status" label="启用状态" valuePropName="checked">
            <Switch checkedChildren="启用" unCheckedChildren="禁用" />
          </Form.Item>
        </Form>
      </Modal>

      {/* 分配权限：抽屉形式，树区域独立滚动，权限再多也不会撑成一长条 */}
      <Drawer
        title={`分配权限 - ${authGroup?.title || ''}`}
        placement="right"
        width={640}
        open={authOpen}
        onClose={() => setAuthOpen(false)}
        destroyOnClose
        styles={{ body: { display: 'flex', flexDirection: 'column', padding: 0 } }}
        footer={
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <Typography.Text type="secondary">
              {isAll ? '全部权限' : `已选 ${checkedKeys.length} / ${allLeafKeys.length} 项`}
            </Typography.Text>
            <Space>
              <Button onClick={() => setAuthOpen(false)}>取消</Button>
              <Button type="primary" loading={authSubmitting} onClick={handleAuthorize}>
                保存授权
              </Button>
            </Space>
          </div>
        }
      >
        {/* 工具条：固定不滚动 */}
        <div style={{ padding: '16px 24px', borderBottom: '1px solid #f0f0f0', flex: 'none' }}>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 12 }}
            message="勾选该用户组可访问的功能，保存后组内成员重新登录即可生效"
          />
          <Checkbox checked={isAll} onChange={toggleAll}>
            超级管理员权限（全部功能）
          </Checkbox>
          <Divider style={{ margin: '12px 0' }} />
          <Space wrap>
            <Input
              allowClear
              value={keyword}
              onChange={(e) => setKeyword(e.target.value)}
              placeholder="搜索权限名称 / 标识"
              style={{ width: 240 }}
            />
            <Button size="small" disabled={isAll} onClick={() => setCheckedKeys(allLeafKeys)}>
              全选
            </Button>
            <Button size="small" disabled={isAll} onClick={() => setCheckedKeys([])}>
              清空
            </Button>
          </Space>
        </div>

        {/* 权限树：唯一滚动区，占满剩余高度 */}
        <div style={{ flex: 1, minHeight: 0, overflow: 'auto', padding: '12px 24px 24px' }}>
          {treeData.length === 0 ? (
            <Empty description="暂无权限菜单数据" />
          ) : filteredTree.length === 0 ? (
            <Empty description={`未找到匹配「${keyword}」的权限`} />
          ) : (
            <Tree
              // 搜索时重挂载以保证全部展开
              key={keyword ? `kw-${keyword}` : 'all'}
              checkable
              selectable={false}
              disabled={isAll}
              treeData={filteredTree}
              checkedKeys={checkedKeys}
              onCheck={(keys) => setCheckedKeys(Array.isArray(keys) ? keys : keys.checked)}
              defaultExpandAll
            />
          )}
        </div>
      </Drawer>
    </div>
  );
}
