import { useCallback, useEffect, useState } from 'react';
import { Button, Card, DatePicker, Form, Input, Popconfirm, Select, Space, Table, Tag, Typography, message } from 'antd';
import { ClearOutlined, ReloadOutlined, SearchOutlined } from '@ant-design/icons';
import { logApi } from '../api';
import { useAuth } from '../store/AuthContext';

const { RangePicker } = DatePicker;

const MODULE_OPTIONS = [
  { label: '认证', value: 'auth' },
  { label: '内容', value: 'content' },
  { label: '分类', value: 'category' },
  { label: '管理员', value: 'admin' },
  { label: '系统', value: 'system' },
];

export default function LogList() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10 });

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await logApi.list(query);
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
    const [start, end] = values.range || [];
    setQuery({
      page: 1,
      limit: query.limit,
      keyword: values.keyword || undefined,
      module: values.module,
      start_date: start ? start.format('YYYY-MM-DD') : undefined,
      end_date: end ? end.format('YYYY-MM-DD') : undefined,
    });
  };

  const handleClear = async () => {
    await logApi.clear();
    message.success('日志已清空');
    fetchList();
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          操作日志
        </Typography.Title>
        <Space>
          <Button icon={<ReloadOutlined />} onClick={fetchList}>
            刷新
          </Button>
          {hasPermission('log.clear') && (
            <Popconfirm title="确认清空全部操作日志？" onConfirm={handleClear}>
              <Button danger icon={<ClearOutlined />}>
                清空日志
              </Button>
            </Popconfirm>
          )}
        </Space>
      </div>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={form} layout="inline" onFinish={handleSearch} style={{ rowGap: 12 }}>
          <Form.Item name="keyword">
            <Input allowClear prefix={<SearchOutlined />} placeholder="操作人 / 描述" style={{ width: 220 }} />
          </Form.Item>
          <Form.Item name="module">
            <Select allowClear placeholder="全部模块" style={{ width: 140 }} options={MODULE_OPTIONS} />
          </Form.Item>
          <Form.Item name="range">
            <RangePicker placeholder={['开始日期', '结束日期']} />
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
        <Table
          rowKey="id"
          loading={loading}
          dataSource={list}
          pagination={{
            current: query.page,
            pageSize: query.limit,
            total,
            showSizeChanger: true,
            showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery((prev) => ({ ...prev, page, limit })),
          }}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '操作人', dataIndex: 'admin_name', width: 120 },
            {
              title: '模块',
              dataIndex: 'module_text',
              width: 90,
              render: (v) => <Tag>{v}</Tag>,
            },
            { title: '动作', dataIndex: 'action', width: 120 },
            { title: '描述', dataIndex: 'description', ellipsis: true },
            { title: 'IP', dataIndex: 'ip', width: 140 },
            { title: '时间', dataIndex: 'create_time', width: 170 },
          ]}
        />
      </Card>
    </div>
  );
}
