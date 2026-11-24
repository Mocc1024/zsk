import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  Button,
  Card,
  DatePicker,
  Dropdown,
  Empty,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tag,
  Tooltip,
  Typography,
  message,
} from 'antd';
import {
  CopyOutlined,
  DeleteOutlined,
  DownOutlined,
  EditOutlined,
  ExportOutlined,
  MessageOutlined,
  PlusOutlined,
  PushpinOutlined,
  ReloadOutlined,
  SearchOutlined,
} from '@ant-design/icons';
import { categoryApi, commentAuditApi, contentApi, tagApi } from '../api';
import { useAuth } from '../store/AuthContext';
import { CONTENT_STATUS, contentStatusColor, contentStatusLabel } from '../utils/constants';

const { RangePicker } = DatePicker;

// 前台站点地址。当前前后台同域部署（后台 /admin/，前台 /），
// 用相对站点根路径即可；若前后台分离部署，设置 VITE_FRONT_BASE 指向前台域名。
const FRONT_BASE = import.meta.env.VITE_FRONT_BASE || '';

/** 前台只对外展示「已发布」的内容（ContentReader 按 status=1 过滤） */
const frontUrl = (id) => `${FRONT_BASE}/content/${id}`;

/** 从 URL 读状态筛选值：看板「草稿内容」跳转过来时带 ?status=0 */
const readUrlStatus = (params) => {
  const raw = params.get('status');
  if (raw === null || raw === '') {
    return undefined;
  }
  const status = Number(raw);

  return Number.isNaN(status) ? undefined : status;
};

export default function ContentList() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [categories, setCategories] = useState([]);
  const [tags, setTags] = useState([]);
  const [selectedRowKeys, setSelectedRowKeys] = useState([]);
  // 正在管理评论的内容行：非空时弹出该文章的评论管理弹窗
  const [commentRow, setCommentRow] = useState(null);
  // 首屏就带上 URL 上的状态筛选。若挪到 useEffect 里补，
  // 会先按「未筛选」请求一次、再按筛选请求一次，页面闪一下全部列表才变成草稿。
  const urlStatus = readUrlStatus(searchParams);
  const [query, setQuery] = useState({ page: 1, limit: 10, status: urlStatus });

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

  // 参数只是一次性的「跳转意图」，用完后从 URL 上摘掉。
  // 留着的话，用户手动改成别的筛选再刷新，URL 又会把筛选项拽回草稿，很难解释。
  //
  // 这里不能再无条件 setQuery：初值已经在 useState / initialValues 里带上，
  // 值相同时下面这一行会原样返回 prev，React 跳过更新，也就不会有第二次请求。
  useEffect(() => {
    const status = readUrlStatus(searchParams);
    if (status === undefined) {
      return;
    }

    setQuery((prev) =>
      prev.status === status && prev.page === 1 ? prev : { ...prev, page: 1, status }
    );
    form.setFieldsValue({ status });

    const next = new URLSearchParams(searchParams);
    next.delete('status');
    setSearchParams(next, { replace: true });
  }, [searchParams]);

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
        render: (text, record) => {
          // 编辑入口在操作列；标题点开是前台页面，新标签页打开，
          // 免得从后台跳走后还得点回来。
          const viewable = Number(record.status) === 1;

          // flex 布局：「置顶」标签不收缩，标题占剩余宽度，超出显示省略号。
          // 不用 antd 的 Space——它给子项包的 wrapper 不会收缩（min-width: auto），
          // 长标题会把单元格撑爆后被硬裁，没有省略号。
          const titleStyle = {
            flex: '1 1 auto',
            minWidth: 0,
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
          };

          return (
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 }}>
              {record.is_top === 1 && (
                <Tag color="volcano" style={{ flex: '0 0 auto' }}>
                  置顶
                </Tag>
              )}
              {viewable ? (
                <a
                  href={frontUrl(record.id)}
                  target="_blank"
                  rel="noreferrer"
                  title={`${text}（在前台查看）`}
                  style={titleStyle}
                >
                  {text}
                </a>
              ) : (
                // 草稿 / 已下架在前台查不到，给了链接点过去只会看到「内容不存在」
                <Tooltip title="未发布，前台暂不可见">
                  <span style={{ ...titleStyle, color: '#8c8c8c' }}>{text}</span>
                </Tooltip>
              )}
            </div>
          );
        },
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
        width: 260,
        render: (values) => {
          const names = Array.isArray(values) ? values : [];
          if (!names.length) return <span style={{ color: '#bfbfbf' }}>—</span>;
          // 全部展示不折叠：超出列宽时自动换行，行高随标签数量自适应
          return (
            <Space size={4} wrap>
              {names.map((name) => (
                <Tag key={name} color="blue">
                  {name}
                </Tag>
              ))}
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
      // comment_count 是冗余计数，只统计「审核通过」的评论，
      // 待审和已驳回的不计 —— 与评论审核列表的口径一致
      { title: '评论数', dataIndex: 'comment_count', width: 90, sorter: true },
      { title: '排序', dataIndex: 'sort', width: 80, sorter: true },
      { title: '创建时间', dataIndex: 'create_time', width: 170 },
      {
        title: '操作',
        key: 'action',
        width: 440,
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
            {hasPermission('comment.list') && (
              <Button
                type="link"
                size="small"
                icon={<MessageOutlined />}
                onClick={() => setCommentRow(record)}
              >
                评论
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
        <Form
          form={form}
          layout="inline"
          onFinish={handleSearch}
          initialValues={{ status: urlStatus }}
          style={{ rowGap: 12 }}
        >
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

      {/* 单篇文章的评论管理：查看 / 编辑 / 删除 */}
      <ContentCommentsModal row={commentRow} onClose={() => setCommentRow(null)} />
    </div>
  );
}

/* ---------------- 文章评论管理弹窗 ---------------- */

/** 评论状态文案，与评论审核页一致 */
const COMMENT_STATUS = { 0: '待审核', 1: '已通过', 2: '已驳回' };

function ContentCommentsModal({ row, onClose }) {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);
  // 正在编辑的评论行；非空时弹出编辑框
  const [editing, setEditing] = useState(null);
  const [editText, setEditText] = useState('');

  // row 为 null（弹窗关闭）时 hook 照常执行但不发请求
  const loadList = useCallback(async () => {
    if (!row) {
      return;
    }
    setLoading(true);
    try {
      const res = await commentAuditApi.list({ page, limit: 10, content_id: row.id, keyword });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, row, keyword]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  // 切换文章（含重新打开弹窗）时重置页码与搜索词，避免上一篇文章的筛选状态串场
  const rowId = row?.id;
  useEffect(() => {
    setPage(1);
    setKeyword('');
  }, [rowId]);

  const saveEdit = async () => {
    if (!editText.trim()) {
      message.warning('评论内容不能为空');
      return;
    }
    try {
      await commentAuditApi.update(editing.id, editText);
      message.success('已保存');
      setEditing(null);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const remove = async (record) => {
    try {
      await commentAuditApi.remove(record.id);
      message.success('已删除');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: '评论内容', dataIndex: 'content', ellipsis: true },
    { title: '用户', dataIndex: 'nickname', width: 100, ellipsis: true },
    {
      title: '状态',
      dataIndex: 'status',
      width: 84,
      render: (v) => {
        const map = { 0: ['orange', '待审核'], 1: ['green', '已通过'], 2: ['red', '已驳回'] };
        const [color, text] = map[v] || ['default', v];
        return <Tag color={color}>{text}</Tag>;
      },
    },
    { title: '时间', dataIndex: 'create_time', width: 150 },
    {
      title: '操作',
      key: 'action',
      width: 120,
      render: (_, record) => (
        <Space size={0}>
          <Button
            type="link"
            size="small"
            onClick={() => {
              setEditing(record);
              setEditText(record.content);
            }}
          >
            编辑
          </Button>
          <Popconfirm title="确认删除该评论？" okText="删除" cancelText="取消" onConfirm={() => remove(record)}>
            <Button type="link" size="small" danger>
              删除
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <>
      {/* 评论列表弹窗 */}
      <Modal
        title={row ? `评论管理：${row.title}` : '评论管理'}
        open={!!row}
        onCancel={() => {
          onClose();
          setKeyword('');
          setPage(1);
        }}
        footer={null}
        width={760}
        destroyOnClose
      >
        <Input.Search
          placeholder="搜索评论内容或用户昵称"
          allowClear
          style={{ marginBottom: 12 }}
          onSearch={(v) => {
            setKeyword(v.trim());
            setPage(1);
          }}
        />
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={list}
          locale={{ emptyText: <Empty description="没有符合条件的评论" /> }}
          pagination={{
            current: page,
            pageSize: 10,
            total,
            showTotal: (t) => `共 ${t} 条`,
            onChange: setPage,
          }}
        />
      </Modal>

      {/* 编辑弹窗：与列表弹窗同级渲染，避免嵌套遮罩叠加 */}
      <Modal
        title="编辑评论"
        open={!!editing}
        onOk={saveEdit}
        onCancel={() => setEditing(null)}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Input.TextArea
          rows={4}
          value={editText}
          onChange={(e) => setEditText(e.target.value)}
          maxLength={2000}
          showCount
          style={{ marginBottom: 8 }}
          placeholder="修正后的评论内容（保存后前台立即生效）"
        />
      </Modal>
    </>
  );
}
