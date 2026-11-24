import { useCallback, useEffect, useState } from 'react';
import {
  Button, Card, Form, Input, InputNumber, Modal, Popconfirm, Row, Col,
  Select, Space, Statistic, Table, Tag, Typography, message,
} from 'antd';
import { EditOutlined, PlusOutlined, SearchOutlined, UndoOutlined } from '@ant-design/icons';
import { fitnessStoreApi } from '../../api';
import { useAuth } from '../../store/AuthContext';

const money = (v) => `¥ ${Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** 全民健身 - 门店管理：切换门店查看实时指标 + 门店增删改 */
export default function FitnessStore() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [searchForm] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10, deleted: 0 });
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [currentId, setCurrentId] = useState(null);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fitnessStoreApi.list(query);
      const rows = res.data.list || [];
      setList(rows);
      setTotal(res.data.total || 0);
      setCurrentId((prev) => (prev && rows.some((r) => r.id === prev) ? prev : rows[0]?.id ?? null));
    } finally {
      setLoading(false);
    }
  }, [query]);

  useEffect(() => { fetchList(); }, [fetchList]);

  const current = list.find((r) => r.id === currentId);
  const metrics = current?.metrics;

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ sort: 0 });
    setModalOpen(true);
  };

  const openEdit = (record) => {
    setEditing(record);
    form.setFieldsValue({
      name: record.name, address: record.address,
      sort: record.sort,
    });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      const payload = { ...values };
      if (editing) {
        await fitnessStoreApi.update(editing.id, payload);
        message.success('保存成功');
      } else {
        await fitnessStoreApi.create(payload);
        message.success('新增成功');
      }
      setModalOpen(false);
      fetchList();
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id) => {
    await fitnessStoreApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const handleRestore = async (id) => {
    await fitnessStoreApi.restore(id);
    message.success('恢复成功');
    fetchList();
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>门店管理</Typography.Title>
        {hasPermission('fitness.store.create') && (
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>新增门店</Button>
        )}
      </div>

      <Card bordered={false} style={{ marginBottom: 16 }} title="当前门店概况"
        extra={
          <Space>
            <span style={{ color: '#999' }}>切换门店：</span>
            <select
              value={currentId ?? ''}
              onChange={(e) => setCurrentId(Number(e.target.value))}
              style={{ padding: '4px 8px', minWidth: 180, borderRadius: 6, border: '1px solid #d9d9d9' }}
            >
              {list.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </Space>
        }
      >
        <Row gutter={[16, 16]}>
          <Col xs={24} sm={12} md={6}><Statistic title="总业绩" value={metrics?.performance?.total || 0} precision={2} prefix="¥" /></Col>
          <Col xs={24} sm={12} md={6}><Statistic title="今年业绩" value={metrics?.performance?.year || 0} precision={2} prefix="¥" /></Col>
          <Col xs={24} sm={12} md={6}><Statistic title="本月业绩" value={metrics?.performance?.month || 0} precision={2} prefix="¥" /></Col>
          <Col xs={24} sm={12} md={6}><Statistic title="今日业绩" value={metrics?.performance?.today || 0} precision={2} prefix="¥" /></Col>
          <Col xs={24} sm={12} md={6}><Statistic title="已上课时" value={metrics?.lesson_done || 0} suffix="次" /></Col>
          <Col xs={24} sm={12} md={6}><Statistic title="待上课时" value={metrics?.lesson_pending || 0} suffix="次" /></Col>
          <Col xs={24} sm={8} md={4}><Statistic title="会员数" value={metrics?.member_count || 0} /></Col>
          <Col xs={24} sm={8} md={4}><Statistic title="教练数" value={metrics?.coach_count || 0} /></Col>
          <Col xs={24} sm={8} md={4}><Statistic title="企业数" value={metrics?.enterprise_count || 0} /></Col>
        </Row>
      </Card>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={searchForm} layout="inline" initialValues={{ deleted: 0 }}
          onFinish={(v) => setQuery((p) => ({ ...p, page: 1, keyword: v.keyword || undefined, deleted: v.deleted }))} style={{ rowGap: 12 }}>
          <Form.Item name="keyword">
            <Input allowClear prefix={<SearchOutlined />} placeholder="门店名称 / 地址" style={{ width: 240 }} />
          </Form.Item>
          <Form.Item name="deleted">
            <Select placeholder="状态" style={{ width: 130 }}
              options={[{ label: '启用', value: 0 }, { label: '禁用', value: 1 }]} />
          </Form.Item>
          <Form.Item>
            <Space>
              <Button type="primary" htmlType="submit">查询</Button>
              <Button onClick={() => { searchForm.resetFields(); setQuery({ page: 1, limit: query.limit, deleted: 0 }); }}>重置</Button>
            </Space>
          </Form.Item>
        </Form>
      </Card>

      <Card bordered={false}>
        <Table
          rowKey="id"
          loading={loading}
          dataSource={list}
          scroll={{ x: 900 }}
          pagination={{
            current: query.page, pageSize: query.limit, total,
            showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery((p) => ({ ...p, page, limit })),
          }}
          onRow={(record) => ({ onClick: () => setCurrentId(record.id) })}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '门店名称', dataIndex: 'name', width: 160 },
            { title: '地址', dataIndex: 'address', ellipsis: true },
            { title: '排序', dataIndex: 'sort', width: 80 },
            {
              title: '状态', dataIndex: 'status', width: 90,
              render: (_, record) => record.delete_time
                ? <Tag color="red">禁用</Tag>
                : <Tag color="green">启用</Tag>,
            },
            {
              title: '操作', key: 'action', width: 160, fixed: 'right',
              render: (_, record) => (
                <Space size={4}>
                  {record.delete_time ? (hasPermission('fitness.store.delete') && (
                    <Popconfirm title="确认恢复该门店？" onConfirm={() => handleRestore(record.id)}>
                      <Button type="link" size="small" icon={<UndoOutlined />} onClick={(e) => e.stopPropagation()}>恢复</Button>
                    </Popconfirm>
                  )) : (
                    <>
                      {hasPermission('fitness.store.update') && (
                        <Button type="link" size="small" icon={<EditOutlined />} onClick={(e) => { e.stopPropagation(); openEdit(record); }}>编辑</Button>
                      )}
                      {hasPermission('fitness.store.delete') && (
                        <Popconfirm title="确认删除该门店？存在关联档案时将被拒绝" onConfirm={() => handleDelete(record.id)}>
                          <Button type="link" size="small" danger onClick={(e) => e.stopPropagation()}>删除</Button>
                        </Popconfirm>
                      )}
                    </>
                  )}
                </Space>
              ),
            },
          ]}
        />
      </Card>

      <Modal
        title={editing ? '编辑门店' : '新增门店'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={handleSubmit}
        confirmLoading={submitting}
        okText="保存" cancelText="取消"
        width={520} destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="name" label="门店名称" rules={[{ required: true, message: '请输入门店名称' }]}>
            <Input placeholder="如：XX健身·中心店" />
          </Form.Item>
          <Form.Item name="address" label="门店地址"><Input placeholder="选填" /></Form.Item>
          <Form.Item name="sort" label="排序"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
