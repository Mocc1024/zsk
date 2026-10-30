import { useCallback, useEffect, useState } from 'react';
import {
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
  message,
} from 'antd';
import { tagApi } from '../api';
import { ENABLE_OPTIONS } from '../utils/constants';

export default function TagList() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [loading, setLoading] = useState(false);
  const [keyword, setKeyword] = useState('');
  const [searchText, setSearchText] = useState('');
  const [editing, setEditing] = useState(null);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await tagApi.list({ page, limit: pageSize, keyword: searchText || undefined });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, pageSize, searchText]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const openCreate = () => {
    setEditing({});
    form.setFieldsValue({ name: '', slug: '', sort: 0, status: 1 });
  };

  const openEdit = (row) => {
    setEditing(row);
    form.setFieldsValue({
      name: row.name,
      slug: row.slug,
      sort: row.sort,
      status: row.status,
    });
  };

  const handleSave = async () => {
    const values = await form.validateFields();
    setSaving(true);
    try {
      if (editing?.id) {
        await tagApi.update(editing.id, values);
      } else {
        await tagApi.create(values);
      }
      message.success('保存成功');
      setEditing(null);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id) => {
    try {
      await tagApi.remove(id);
      message.success('删除成功');
      loadList();
    } catch (e) {
      // 被使用时后端会返回明确提示
    }
  };

  const columns = [
    { title: '标签名', dataIndex: 'name', render: (name) => <Tag color="blue">{name}</Tag> },
    { title: '别名', dataIndex: 'slug', render: (slug) => slug || '-' },
    {
      title: '引用内容数',
      dataIndex: 'count',
      width: 120,
      render: (count) => <Tag color={Number(count) > 0 ? 'orange' : 'default'}>{Number(count) || 0}</Tag>,
    },
    { title: '排序', dataIndex: 'sort', width: 90 },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (status) => (
        <Tag color={Number(status) === 1 ? 'green' : 'red'}>
          {Number(status) === 1 ? '启用' : '禁用'}
        </Tag>
      ),
    },
    { title: '创建时间', dataIndex: 'create_time', width: 180, render: (t) => t || '-' },
    {
      title: '操作',
      key: 'action',
      width: 150,
      fixed: 'right',
      render: (_, row) => (
        <Space size={4}>
          <Button type="link" size="small" onClick={() => openEdit(row)}>
            编辑
          </Button>
          <Popconfirm
            title="确认删除该标签？"
            description={Number(row.count) > 0 ? '该标签正被内容使用，删除会被拒绝' : undefined}
            okText="确认"
            cancelText="取消"
            onConfirm={() => handleDelete(row.id)}
          >
            <Button type="link" size="small" danger disabled={Number(row.count) > 0}>
              删除
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <>
      <Card
        title="标签管理"
        extra={
          <Space>
            <Input.Search
              placeholder="搜索标签名 / 别名"
              allowClear
              style={{ width: 220 }}
              value={keyword}
              onChange={(e) => setKeyword(e.target.value)}
              onSearch={(value) => {
                setSearchText(value.trim());
                setPage(1);
              }}
            />
            <Button type="primary" onClick={openCreate}>
              新增标签
            </Button>
          </Space>
        }
      >
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={list}
          locale={{ emptyText: <Empty description="还没有标签，可在编辑内容时直接创建" /> }}
          pagination={{
            current: page,
            pageSize,
            total,
            showSizeChanger: true,
            showTotal: (t) => `共 ${t} 个标签`,
            onChange: (p, s) => {
              setPage(p);
              setPageSize(s);
            },
          }}
        />
      </Card>

      <Modal
        title={editing?.id ? '编辑标签' : '新增标签'}
        open={!!editing}
        onCancel={() => setEditing(null)}
        onOk={handleSave}
        confirmLoading={saving}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="name" label="标签名" rules={[{ required: true, message: '请输入标签名' }]}>
            <Input placeholder="如：产品手册" maxLength={20} showCount />
          </Form.Item>
          <Form.Item name="slug" label="别名">
            <Input placeholder="用于 URL，留空则自动生成" maxLength={60} />
          </Form.Item>
          <Form.Item name="sort" label="排序">
            <InputNumber min={0} max={9999} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="status" label="状态">
            <Select options={ENABLE_OPTIONS} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
