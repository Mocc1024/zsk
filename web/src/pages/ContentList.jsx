import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Button,
  Card,
  DatePicker,
  Dropdown,
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
import {
  DeleteOutlined,
  EditOutlined,
  PlusOutlined,
  PushpinOutlined,
  ReloadOutlined,
  SearchOutlined,
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { categoryApi, contentApi } from '../api';
import { useAuth } from '../store/AuthContext';
import { CONTENT_STATUS, contentStatusColor, contentStatusLabel } from '../utils/constants';

const { RangePicker } = DatePicker;

export default function ContentList() {
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [categories, setCategories] = useState([]);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  const [query, setQuery] = useState({ page: 1, limit: 10 });

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await contentApi.list(query);
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
    categoryApi.options().then((res) => setCategories(res.data || []));
  }, []);

  const handleSearch = (values) => {
    const [start, end] = values.range || [];
    setQuery((prev) => ({
      ...prev,
      page: 1,
      keyword: values.keyword || undefined,
      category_id: values.category_id,
      status: values.status,
      start_date: start ? start.format('YYYY-MM-DD') : undefined,
      end_date: end ? end.format('YYYY-MM-DD') : undefined,
    }));
  };

  const handleStatusChange = async (record, status) => {
    await contentApi.setStatus(record.id, status);
    message.success('状态已更新');
    fetchList();
  };

  const handleTopChange = async (record) => {
    await contentApi.setTop(record.id, record.is_top === 1 ? 0 : 1);
    message.success(record.is_top === 1 ? '已取消置顶' : '已置顶');
    fetchList();
  };

  const handleDelete = async (id) => {
    await contentApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const handleBatchDelete = async () => {
    await contentApi.batchDelete(selectedRowKeys);
    message.success('批量删除成功');
    setSelectedRowKeys([]);
    fetchList();
  };

  const columns = useMemo(
    () => [
      {
        title: 'ID',
        dataIndex: 'id',
        width: 70,
      },
      {
        title: '标题',
        dataIndex: 'title',
        ellipsis: true,
        render: (text, record) => (
          <Space size={6}>
            {record.is_top === 1 && <Tag color="volcano">置顶</Tag>}
            <a onClick={() => navigate(`/content/edit/${record.id}`)}>{text}</a>
          </Space>
        ),
      },
      {
        title: '分类',
        dataIndex: 'category_name',
        width: 120,
        render: (v) => v || <Tag>未分类</Tag>,
      },
      { title: '作者', dataIndex: 'author', width: 100 },
      {
        title: '状态',
        dataIndex: 'status',
        width: 100,
        render: (v) => <Tag color={contentStatusColor(v)}>{contentStatusLabel(v)}</Tag>,
      },
      { title: '浏览量', dataIndex: 'views', width: 90, sorter: true },
      { title: '排序', dataIndex: 'sort', width: 80, sorter: true },
      { title: '创建时间', dataIndex: 'create_time', width: 170 },
      {
        title: '操作',
        key: 'action',
        width: 220,
        fixed: 'right',
        render: (_, record) => (
          <Space size={4}>
            {hasPermission('content.update') && (
              <Button
                type="link"
                size="small"
                icon={<EditOutlined />}
                onClick={() => navigate(`/content/edit/${record.id}`)}
              >
                编辑
              </Button>
            )}
            {hasPermission('content.status') && (
              <Dropdown
                menu={{
                  items: CONTENT_STATUS.map((item) => ({ key: item.value, label: `设为${item.label}` })),
                  onClick: ({ key }) => handleStatusChange(record, Number(key)),
                }}
              >
                <Button type="link" size="small">
                  状态
                </Button>
              </Dropdown>
            )}
            {hasPermission('content.top') && (
              <Button
                type="link"
                size="small"
                icon={<PushpinOutlined />}
                onClick={() => handleTopChange(record)}
              >
                {record.is_top === 1 ? '取消置顶' : '置顶'}
              </Button>
            )}
            {hasPermission('content.delete') && (
              <Popconfirm title="确认删除该内容？" onConfirm={() => handleDelete(record.id)}>
                <Button type="link" size="small" danger icon={<DeleteOutlined />}>
                删除
              </Button>
              </Popconfirm>
            )}
          </Space>
        ),
      },
    ],
    [navigate, fetchList]
  );

  return (
    <div>
      <Typography.Title level={4} style={{ marginTop: 0, marginBottom: 16 }}>
        内容管理
      </Typography.Title>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={form} layout="inline" onFinish={handleSearch} style={{ rowGap: 12 }}>
          <Form.Item name="keyword">
            <Input allowClear prefix={<SearchOutlined />} placeholder="标题 / 作者 / 摘要" style={{ width: 200 }} />
          </Form.Item>
          <Form.Item name="category_id">
            <Select
              allowClear
              placeholder="全部分类"
              style={{ width: 150 }}
              options={categories.map((item) => ({ label: item.name, value: item.id }))}
            />
          </Form.Item>
          <Form.Item name="status">
            <Select
              allowClear
              placeholder="全部状态"
              style={{ width: 130 }}
              options={CONTENT_STATUS.map((item) => ({ label: item.label, value: item.value }))}
            />
          </Form.Item>
          <Form.Item name="range">
            <RangePicker placeholder={['创建开始', '创建结束']} />
          </Form.Item>
          <Form.Item>
            <Space>
              <Button type="primary" htmlType="submit">
                查询
              </Button>
              <Button
                onClick={() => {
                  form.resetFields();
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
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
          <Space>
            {hasPermission('content.create') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navigate('/content/create')}>
                新建内容
              </Button>
            )}
            {hasPermission('content.batch-delete') && (
            <Popconfirm
              title={`确认删除选中的 ${selectedRowKeys.length} 条内容？`}
              onConfirm={handleBatchDelete}
              disabled={selectedRowKeys.length === 0}
            >
              <Button danger icon={<DeleteOutlined />} disabled={selectedRowKeys.length === 0}>
                批量删除
              </Button>
            </Popconfirm>
            )}
            {selectedRowKeys.length > 0 && (
              <span style={{ color: '#8c8c8c' }}>已选择 {selectedRowKeys.length} 项</span>
            )}
          </Space>
          <Button icon={<ReloadOutlined />} onClick={fetchList}>
            刷新
          </Button>
        </div>

        <Table
          rowKey="id"
          loading={loading}
          columns={columns}
          dataSource={list}
          scroll={{ x: 1200 }}
          rowSelection={{ selectedRowKeys, onChange: setSelectedRowKeys }}
          onChange={(pagination, filters, sorter) => {
            if (sorter?.field) {
              setQuery((prev) => ({
                ...prev,
                sort_field: `c.${sorter.field}`,
                sort_order: sorter.order === 'ascend' ? 'asc' : 'desc',
              }));
            }
          }}
          pagination={{
            current: query.page,
            pageSize: query.limit,
            total,
            showSizeChanger: true,
            showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery((prev) => ({ ...prev, page, limit })),
          }}
        />
      </Card>
    </div>
  );
}
