import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Button,
  Card,
  DatePicker,
  Dropdown,
  Form,
  Input,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import {
  CopyOutlined,
  DeleteOutlined,
  DownOutlined,
  EditOutlined,
  ExportOutlined,
  PlusOutlined,
  PushpinOutlined,
  ReloadOutlined,
  SearchOutlined,
} from '@ant-design/icons';
import { categoryApi, contentApi, tagApi } from '../api';
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
  const [tags, setTags] = useState([]);
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

  useEffect(() => {
    tagApi.options().then((res) => setTags(res.data || []));
  }, []);

  const handleSearch = (values) => {
    const [start, end] = values.range || [];
    setQuery((prev) => ({
      ...prev,
      page: 1,
      keyword: values.keyword || undefined,
      category_id: values.category_id,
      status: values.status,
      tag_id: values.tag_id || undefined,
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

  /** 复制为草稿 */
  const handleCopy = async (record) => {
    const res = await contentApi.copy(record.id, {});
    message.success(res.msg || '已复制为草稿');
    fetchList();
  };

  /** 导出：把后端返回的文本/二进制组装成文件下载 */
  const handleExport = async (format) => {
    const ids = selectedRowKeys.length ? selectedRowKeys : undefined;
    try {
      const res = await contentApi.exportFile({ format, ids });
      const { filename, mime, encoding, content } = res.data || {};

      let blob;
      if (encoding === 'base64') {
        const binary = atob(content || '');
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
          bytes[i] = binary.charCodeAt(i);
        }
        blob = new Blob([bytes], { type: mime });
      } else {
        blob = new Blob([content || ''], { type: `${mime};charset=utf-8` });
      }

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);

      message.success(res.msg || '导出成功');
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
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
        width: 260,
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
        title: '标签',
        dataIndex: 'tags',
        width: 180,
        render: (values) => {
          const names = Array.isArray(values) ? values : [];
          if (!names.length) return <span style={{ color: '#bfbfbf' }}>—</span>;
          return (
            <Space size={4} wrap>
              {names.slice(0, 3).map((name) => (
                <Tag key={name} color="blue">
                  {name}
                </Tag>
              ))}
              {names.length > 3 && <Tag>+{names.length - 3}</Tag>}
            </Space>
          );
        },
      },
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
        width: 400,
        fixed: 'right',
        render: (_, record) => (
          <Space size={0} style={{ flexWrap: 'nowrap' }}>
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
            {hasPermission('content.copy') && (
              <Button
                type="link"
                size="small"
                icon={<CopyOutlined />}
                onClick={() => handleCopy(record)}
              >
                复制
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
    // 必须依赖 hasPermission：刷新页面时权限是异步恢复的，
    // 缺少该依赖会导致操作列按首次渲染时的空权限缓存下来，按钮全部不显示
    [navigate, fetchList, hasPermission]
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
          <Form.Item name="tag_id">
            <Select
              allowClear
              placeholder="全部标签"
              style={{ width: 150 }}
              showSearch
              optionFilterProp="label"
              options={tags.map((item) => ({ label: item.label, value: item.id }))}
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
            {hasPermission('content.export') && (
              <Dropdown
                menu={{
                  items: [
                    { key: 'csv', label: '导出为 CSV（Excel 可直接打开）' },
                    { key: 'zip', label: '导出 Markdown 压缩包' },
                  ],
                  onClick: ({ key }) => handleExport(key),
                }}
              >
                <Button icon={<ExportOutlined />}>
                  导出{selectedRowKeys.length > 0 ? `（${selectedRowKeys.length}）` : '全部'} <DownOutlined />
                </Button>
              </Dropdown>
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
          scroll={{ x: 1700 }}
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
