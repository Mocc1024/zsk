import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Space,
  Switch,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { EditOutlined, PlusOutlined } from '@ant-design/icons';
import { categoryApi } from '../api';
import { useAuth } from '../store/AuthContext';

export default function CategoryList() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10 });
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await categoryApi.list(query);
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } finally {
      setLoading(false);
    }
  }, [query]);

  useEffect(() => {
    fetchList();
  }, [fetchList]);

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ sort: 0, status: 1 });
    setModalOpen(true);
  };

  const openEdit = async (record) => {
    const res = await categoryApi.detail(record.id);
    setEditing(res.data);
    form.setFieldsValue({ ...res.data, status: Number(res.data.status) === 1 });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      if (editing) {
        await categoryApi.update(editing.id, values);
        message.success('保存成功');
      } else {
        await categoryApi.create(values);
        message.success('新增成功');
      }
      setModalOpen(false);
      fetchList();
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id) => {
    await categoryApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const handleStatus = async (record) => {
    await categoryApi.setStatus(record.id, record.status === 1 ? 0 : 1);
    message.success(record.status === 1 ? '已禁用' : '已启用');
    fetchList();
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          分类管理
        </Typography.Title>
        {hasPermission('category.create') && (
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
            新建分类
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
            { title: '分类名称', dataIndex: 'name' },
            { title: '标识', dataIndex: 'slug', width: 140 },
            { title: '描述', dataIndex: 'description', ellipsis: true },
            { title: '内容数', dataIndex: 'content_count', width: 90 },
            { title: '排序', dataIndex: 'sort', width: 80 },
            {
              title: '状态',
              dataIndex: 'status',
              width: 90,
              render: (v) => <Tag color={v === 1 ? 'green' : 'red'}>{v === 1 ? '启用' : '禁用'}</Tag>,
            },
            {
              title: '操作',
              key: 'action',
              width: 180,
              render: (_, record) => (
                <Space size={4}>
                  {hasPermission('category.update') && (
                    <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>
                      编辑
                    </Button>
                  )}
                  {hasPermission('category.status') && (
                    <Button type="link" size="small" onClick={() => handleStatus(record)}>
                      {record.status === 1 ? '禁用' : '启用'}
                    </Button>
                  )}
                  {hasPermission('category.delete') && (
                    <Popconfirm title="确认删除该分类？" onConfirm={() => handleDelete(record.id)}>
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
        title={editing ? '编辑分类' : '新建分类'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={handleSubmit}
        confirmLoading={submitting}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false} initialValues={{ sort: 0, status: 1 }}>
          <Form.Item name="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }]}>
            <Input placeholder="例如：技术前沿" maxLength={60} />
          </Form.Item>
          <Form.Item name="slug" label="英文标识" extra="用于程序识别，留空自动生成">
            <Input placeholder="例如：tech" />
          </Form.Item>
          <Form.Item name="description" label="分类描述">
            <Input.TextArea rows={2} maxLength={255} />
          </Form.Item>
          <Form.Item name="sort" label="排序值">
            <InputNumber min={0} max={9999} />
          </Form.Item>
          {hasPermission('category.status') && (
            <Form.Item name="status" label="启用状态" valuePropName="checked">
              <Switch checkedChildren="启用" unCheckedChildren="禁用" />
            </Form.Item>
          )}
        </Form>
      </Modal>
    </div>
  );
}
