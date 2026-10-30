import { useCallback, useEffect, useState } from 'react';
import {
  Button,
  Card,
  Descriptions,
  Divider,
  Empty,
  Image,
  Input,
  Modal,
  Popconfirm,
  Space,
  Spin,
  Table,
  Tag,
  Typography,
  message,
} from 'antd';
import { DeleteOutlined, EyeOutlined } from '@ant-design/icons';
import { contentApi } from '../api';
import { contentStatusColor, contentStatusLabel } from '../utils/constants';

export default function ContentTrash() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [loading, setLoading] = useState(false);
  const [keyword, setKeyword] = useState('');
  const [searchText, setSearchText] = useState('');
  const [selectedIds, setSelectedIds] = useState([]);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const handleView = async (id) => {
    setDetail(null);
    setDetailLoading(true);
    try {
      const res = await contentApi.trashDetail(id);
      setDetail(res.data || null);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setDetailLoading(false);
    }
  };

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await contentApi.trash({ page, limit: pageSize, keyword: searchText || undefined });
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

  const handleRestore = async (id) => {
    try {
      await contentApi.restore(id);
      message.success('已恢复');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const handleForceDelete = async (id) => {
    try {
      await contentApi.forceDelete(id);
      message.success('已彻底删除');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const handleBatchRestore = async () => {
    if (!selectedIds.length) {
      message.warning('请先选择要恢复的内容');
      return;
    }
    try {
      const res = await contentApi.batchRestore(selectedIds);
      message.success(res.msg || '已恢复');
      setSelectedIds([]);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const handleBatchForceDelete = async () => {
    if (!selectedIds.length) {
      message.warning('请先选择要彻底删除的内容');
      return;
    }
    try {
      const res = await contentApi.batchForceDelete(selectedIds);
      message.success(res.msg || '已彻底删除');
      setSelectedIds([]);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    {
      title: '标题',
      dataIndex: 'title',
      ellipsis: true,
      render: (title, row) => (
        <Space direction="vertical" size={0}>
          <Typography.Text strong ellipsis style={{ maxWidth: 360 }}>
            {title}
          </Typography.Text>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            {row.category_name || '未分类'}
            {row.author ? ` · ${row.author}` : ''}
          </Typography.Text>
        </Space>
      ),
    },
    {
      title: '状态',
      dataIndex: 'status',
      width: 100,
      render: (status) => (
        <Tag color={contentStatusColor(status)}>{contentStatusLabel(status)}</Tag>
      ),
    },
    {
      title: '删除时间',
      dataIndex: 'delete_time',
      width: 180,
      render: (text) => text || '-',
    },
    {
      title: '操作',
      key: 'action',
      width: 240,
      fixed: 'right',
      render: (_, row) => (
        <Space size={4}>
          <Button
            type="link"
            size="small"
            icon={<EyeOutlined />}
            onClick={() => handleView(row.id)}
          >
            查看
          </Button>
          <Button type="link" size="small" onClick={() => handleRestore(row.id)}>
            恢复
          </Button>
          <Popconfirm
            title="确认彻底删除？"
            description="删除后内容及其标签关系都会消失，不可恢复"
            okText="彻底删除"
            okButtonProps={{ danger: true }}
            cancelText="取消"
            onConfirm={() => handleForceDelete(row.id)}
          >
            <Button type="link" size="small" danger>
              彻底删除
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <Card
      title="内容回收站"
      extra={
        <Space>
          <Input.Search
            placeholder="搜索标题 / 作者 / 摘要"
            allowClear
            style={{ width: 240 }}
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            onSearch={(value) => {
              setSearchText(value.trim());
              setPage(1);
            }}
          />
        </Space>
      }
    >
      {selectedIds.length > 0 && (
        <div style={{ marginBottom: 12 }}>
          <Space>
            <Typography.Text type="secondary">已选 {selectedIds.length} 条</Typography.Text>
            <Button onClick={handleBatchRestore}>批量恢复</Button>
            <Popconfirm
              title={`确认彻底删除选中的 ${selectedIds.length} 条内容？`}
              description="此操作不可恢复"
              okText="彻底删除"
              okButtonProps={{ danger: true }}
              cancelText="取消"
              onConfirm={handleBatchForceDelete}
            >
              <Button danger icon={<DeleteOutlined />}>
                批量彻底删除
              </Button>
            </Popconfirm>
          </Space>
        </div>
      )}

      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        columns={columns}
        dataSource={list}
        locale={{ emptyText: <Empty description="回收站是空的" /> }}
        rowSelection={{
          selectedRowKeys: selectedIds,
          onChange: (keys) => setSelectedIds(keys.map(Number)),
        }}
        pagination={{
          current: page,
          pageSize,
          total,
          showSizeChanger: true,
          showTotal: (t) => `共 ${t} 条`,
          onChange: (p, s) => {
            setPage(p);
            setPageSize(s);
          },
        }}
      />

      <Modal
        title="内容详情（回收站）"
        open={detailLoading || detail !== null}
        onCancel={() => setDetail(null)}
        footer={null}
        width="min(860px, 92vw)"
        centered
      >
        {detailLoading ? (
          <div style={{ textAlign: 'center', padding: '48px 0' }}>
            <Spin />
          </div>
        ) : detail ? (
          <>
            <Descriptions column={2} size="small" bordered>
              <Descriptions.Item label="标题" span={2}>
                {detail.title}
              </Descriptions.Item>
              <Descriptions.Item label="分类">
                {detail.category_name || '未分类'}
              </Descriptions.Item>
              <Descriptions.Item label="作者">{detail.author || '-'}</Descriptions.Item>
              <Descriptions.Item label="状态">
                <Tag color={contentStatusColor(detail.status)}>
                  {contentStatusLabel(detail.status)}
                </Tag>
              </Descriptions.Item>
              <Descriptions.Item label="浏览量">{detail.views ?? 0}</Descriptions.Item>
              <Descriptions.Item label="置顶">{detail.is_top === 1 ? '是' : '否'}</Descriptions.Item>
              <Descriptions.Item label="排序">{detail.sort ?? 0}</Descriptions.Item>
              <Descriptions.Item label="标签" span={2}>
                {Array.isArray(detail.tags) && detail.tags.length ? (
                  <Space size={4} wrap>
                    {detail.tags.map((name) => (
                      <Tag key={name} color="blue">
                        {name}
                      </Tag>
                    ))}
                  </Space>
                ) : (
                  <span style={{ color: '#bfbfbf' }}>—</span>
                )}
              </Descriptions.Item>
              <Descriptions.Item label="发布时间">{detail.publish_time || '-'}</Descriptions.Item>
              <Descriptions.Item label="删除时间">{detail.delete_time || '-'}</Descriptions.Item>
            </Descriptions>

            {detail.cover && (
              <>
                <Divider orientation="left" plain>
                  封面
                </Divider>
                <Image
                  src={detail.cover}
                  height={120}
                  style={{ maxWidth: '100%', objectFit: 'cover' }}
                />
              </>
            )}

            <Divider orientation="left" plain>
              摘要
            </Divider>
            <Typography.Paragraph type={detail.summary ? undefined : 'secondary'}>
              {detail.summary || '（无摘要）'}
            </Typography.Paragraph>

            <Divider orientation="left" plain>
              正文
            </Divider>
            {detail.content ? (
              <div
                className="rich-preview"
                // 正文在保存时已由后端 HtmlFilter 净化
                dangerouslySetInnerHTML={{ __html: detail.content }}
              />
            ) : (
              <Typography.Text type="secondary">（无正文）</Typography.Text>
            )}
          </>
        ) : null}
      </Modal>
    </Card>
  );
}
