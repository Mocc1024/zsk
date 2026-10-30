import { useCallback, useEffect, useState } from 'react';
import {
  AutoComplete,
  Button,
  Card,
  Empty,
  Form,
  Image,
  Input,
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
  FileOutlined,
  FolderOutlined,
  InboxOutlined,
  PlusOutlined,
  VideoCameraOutlined,
} from '@ant-design/icons';
import { attachmentApi, folderApi } from '../api';
import UploadFile from '../components/UploadFile';

const TYPE_OPTIONS = [
  { label: '图片', value: 'image' },
  { label: '视频', value: 'video' },
  { label: '附件', value: 'file' },
];

const TYPE_COLOR = { image: 'blue', video: 'purple', file: 'default' };
const TYPE_TEXT = { image: '图片', video: '视频', file: '附件' };
const TYPE_ICON = {
  image: null,
  video: <VideoCameraOutlined style={{ fontSize: 22 }} />,
  file: <FileOutlined style={{ fontSize: 22 }} />,
};

const DEFAULT_FOLDER = 'default';

const formatSize = (bytes) => {
  const size = Number(bytes) || 0;
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${size} B`;
};

export default function AttachmentList() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(12);
  const [loading, setLoading] = useState(false);
  const [filters, setFilters] = useState({ type: '', folder: '', keyword: '' });
  const [refreshTick, setRefreshTick] = useState(0);

  const [folders, setFolders] = useState([]);
  const [totalCount, setTotalCount] = useState(0);
  const [uploadFolder, setUploadFolder] = useState(DEFAULT_FOLDER);

  const [selectedIds, setSelectedIds] = useState([]);
  const [editRow, setEditRow] = useState(null);
  const [editForm] = Form.useForm();
  const [saving, setSaving] = useState(false);

  const [searchForm] = Form.useForm();

  // 分组弹窗：mode = create | rename
  const [folderModal, setFolderModal] = useState(null);
  const [folderForm] = Form.useForm();
  const [savingFolder, setSavingFolder] = useState(false);

  // 批量移动分组
  const [moveOpen, setMoveOpen] = useState(false);
  const [moveTarget, setMoveTarget] = useState(DEFAULT_FOLDER);
  const [moving, setMoving] = useState(false);

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await attachmentApi.list({
        page,
        limit: pageSize,
        type: filters.type || undefined,
        folder: filters.folder || undefined,
        keyword: filters.keyword || undefined,
      });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, pageSize, filters, refreshTick]);

  const loadFolders = useCallback(async () => {
    try {
      const res = await attachmentApi.folders();
      setFolders(res.data?.list || []);
      setTotalCount(res.data?.total || 0);
    } catch (e) {
      // 忽略
    }
  }, []);

  useEffect(() => {
    loadList();
  }, [loadList]);

  useEffect(() => {
    loadFolders();
  }, [loadFolders, refreshTick]);

  const folderOptions = folders.map((item) => ({
    label: `${item.name}（${item.count}）`,
    value: item.name,
  }));

  const handleSearch = (values) => {
    setFilters((prev) => ({
      ...prev,
      type: values.type || '',
      keyword: (values.keyword || '').trim(),
    }));
    setPage(1);
  };

  const pickFolder = (folder) => {
    setFilters((prev) => ({ ...prev, folder }));
    setPage(1);
  };

  const handleUploaded = () => {
    setPage(1);
    setRefreshTick((t) => t + 1);
  };

  const handleDelete = async (id) => {
    try {
      const res = await attachmentApi.remove(id);
      message.success(res.msg || '删除成功');
      setRefreshTick((t) => t + 1);
    } catch (e) {
      // 被引用时后端会返回明确的中文提示
    }
  };

  const handleBatchDelete = async () => {
    if (!selectedIds.length) {
      message.warning('请先选择要删除的素材');
      return;
    }
    try {
      const res = await attachmentApi.batchDelete(selectedIds);
      const { deleted = 0 } = res.data || {};
      (res.data?.skipped || []).forEach((name) => message.warning(`${name} 被内容引用，已跳过`));
      if (deleted > 0) {
        message.success(`已删除 ${deleted} 个素材`);
      }
      setSelectedIds([]);
      setRefreshTick((t) => t + 1);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const handleMove = async () => {
    if (!selectedIds.length) {
      message.warning('请先选择要移动的素材');
      return;
    }
    setMoving(true);
    try {
      const res = await attachmentApi.move(selectedIds, moveTarget);
      message.success(res.msg || '已移动');
      setSelectedIds([]);
      setMoveOpen(false);
      setRefreshTick((t) => t + 1);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setMoving(false);
    }
  };

  const openEdit = (row) => {
    setEditRow(row);
    editForm.setFieldsValue({ name: row.name, folder: row.folder, alt: row.alt });
  };

  const handleSaveEdit = async () => {
    const values = await editForm.validateFields();
    setSaving(true);
    try {
      await attachmentApi.update(editRow.id, values);
      message.success('保存成功');
      setEditRow(null);
      setRefreshTick((t) => t + 1);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSaving(false);
    }
  };

  const openFolderModal = (mode, folder) => {
    setFolderModal({ mode, id: folder?.id, name: folder?.name || '' });
    folderForm.setFieldsValue({ name: folder?.name || '' });
  };

  const handleSaveFolder = async () => {
    const values = await folderForm.validateFields();
    setSavingFolder(true);
    try {
      const res = folderModal.mode === 'create'
        ? await folderApi.create({ name: values.name })
        : await folderApi.update(folderModal.id, { name: values.name });
      message.success(res.msg || '保存成功');
      setFolderModal(null);
      setRefreshTick((t) => t + 1);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSavingFolder(false);
    }
  };

  const handleDeleteFolder = async (folder) => {
    try {
      const res = await folderApi.remove(folder.id);
      message.success(res.msg || '分组已删除');
      if (filters.folder === folder.name) {
        setFilters((prev) => ({ ...prev, folder: '' }));
      }
      setPage(1);
      setRefreshTick((t) => t + 1);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    {
      title: '预览',
      dataIndex: 'url',
      width: 80,
      render: (url, row) =>
        row.type === 'image' ? (
          <Image src={url} width={48} height={48} style={{ objectFit: 'cover', borderRadius: 4 }} fallback="" />
        ) : (
          <div
            style={{
              width: 48,
              height: 48,
              background: '#fafafa',
              borderRadius: 4,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: '#8c8c8c',
            }}
          >
            {TYPE_ICON[row.type] || <FileOutlined style={{ fontSize: 22 }} />}
          </div>
        ),
    },
    {
      title: '文件名',
      dataIndex: 'name',
      ellipsis: true,
      render: (name, row) => (
        <Space direction="vertical" size={0}>
          <Typography.Text strong ellipsis style={{ maxWidth: 260 }}>
            {name}
          </Typography.Text>
          <Typography.Text type="secondary" style={{ fontSize: 12 }} copyable={{ text: row.url }}>
            {row.ext} · {formatSize(row.size)}
            {row.width ? ` · ${row.width}×${row.height}` : ''}
          </Typography.Text>
        </Space>
      ),
    },
    {
      title: '类型',
      dataIndex: 'type',
      width: 90,
      render: (type) => <Tag color={TYPE_COLOR[type]}>{TYPE_TEXT[type] || type}</Tag>,
    },
    {
      title: '分组',
      dataIndex: 'folder',
      width: 110,
      render: (folder) => <Tag>{folder || DEFAULT_FOLDER}</Tag>,
    },
    {
      title: '引用',
      dataIndex: 'ref_count',
      width: 90,
      render: (count) => (
        <Tag color={Number(count) > 0 ? 'orange' : 'default'}>{Number(count) || 0} 篇</Tag>
      ),
    },
    {
      title: '上传时间',
      dataIndex: 'create_time',
      width: 170,
      render: (text) => text || '-',
    },
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
            title="确认删除该素材？"
            description={Number(row.ref_count) > 0 ? '该素材正被内容引用，删除会被拒绝' : '删除后不可恢复'}
            okText="确认"
            cancelText="取消"
            onConfirm={() => handleDelete(row.id)}
          >
            <Button type="link" size="small" danger disabled={Number(row.ref_count) > 0}>
              删除
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
      {/* ---------- 左侧：分组 ---------- */}
      <Card
        size="small"
        title="分组"
        style={{ width: 220, flex: 'none' }}
        styles={{ body: { padding: '8px 12px' } }}
        extra={
          <Button
            type="text"
            size="small"
            icon={<PlusOutlined />}
            title="新建分组"
            onClick={() => openFolderModal('create')}
          />
        }
      >
        <div
          onClick={() => pickFolder('')}
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            padding: '6px 8px',
            borderRadius: 6,
            cursor: 'pointer',
            background: filters.folder === '' ? '#e6f4ff' : 'transparent',
            color: filters.folder === '' ? '#1677ff' : undefined,
          }}
        >
          <Space size={6}>
            <FolderOutlined />
            <span>全部素材</span>
          </Space>
          <Typography.Text type="secondary">{totalCount}</Typography.Text>
        </div>

        {folders.map((folder) => (
          <div
            key={folder.id}
            onClick={() => pickFolder(folder.name)}
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              padding: '6px 8px',
              borderRadius: 6,
              cursor: 'pointer',
              background: filters.folder === folder.name ? '#e6f4ff' : 'transparent',
              color: filters.folder === folder.name ? '#1677ff' : undefined,
            }}
          >
            <Typography.Text ellipsis style={{ flex: 1, marginRight: 4 }}>
              {folder.name}
            </Typography.Text>
            <Space size={0}>
              <Typography.Text type="secondary" style={{ fontSize: 12, marginRight: 4 }}>
                {folder.count}
              </Typography.Text>
              <Button
                type="text"
                size="small"
                icon={<EditOutlined />}
                title="重命名"
                onClick={(e) => {
                  e.stopPropagation();
                  openFolderModal('rename', folder);
                }}
              />
              <Popconfirm
                title={`确认删除分组「${folder.name}」？`}
                description={
                  folder.name === DEFAULT_FOLDER
                    ? '默认分组不能删除'
                    : folder.count > 0
                      ? `该分组下的 ${folder.count} 个素材会移入 ${DEFAULT_FOLDER}`
                      : '删除后不可恢复'
                }
                okText="删除"
                okButtonProps={{ danger: true }}
                cancelText="取消"
                disabled={folder.name === DEFAULT_FOLDER}
                onConfirm={(e) => {
                  e?.stopPropagation?.();
                  handleDeleteFolder(folder);
                }}
              >
                <Button
                  type="text"
                  size="small"
                  danger
                  icon={<DeleteOutlined />}
                  title={folder.name === DEFAULT_FOLDER ? '默认分组不能删除' : '删除分组'}
                  disabled={folder.name === DEFAULT_FOLDER}
                  onClick={(e) => e.stopPropagation()}
                />
              </Popconfirm>
            </Space>
          </div>
        ))}

        {!folders.length && (
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            暂无分组，点击右上角 + 新建
          </Typography.Text>
        )}
      </Card>

      {/* ---------- 右侧：素材列表 ---------- */}
      <Card
        style={{ flex: 1, minWidth: 0 }}
        title="素材库"
        extra={
          <Space>
            <span style={{ color: '#8c8c8c', fontSize: 12 }}>上传到</span>
            <Select
              value={uploadFolder}
              onChange={setUploadFolder}
              style={{ width: 160 }}
              options={folderOptions.length ? folderOptions : [{ label: DEFAULT_FOLDER, value: DEFAULT_FOLDER }]}
              placeholder="选择分组"
            />
            <UploadFile type="image" folder={uploadFolder} onSuccess={handleUploaded}>
              <Button icon={<InboxOutlined />}>上传图片</Button>
            </UploadFile>
            <UploadFile type="video" folder={uploadFolder} onSuccess={handleUploaded}>
              <Button>上传视频</Button>
            </UploadFile>
            <UploadFile type="file" folder={uploadFolder} onSuccess={handleUploaded}>
              <Button>上传附件</Button>
            </UploadFile>
          </Space>
        }
      >
        <Form form={searchForm} layout="inline" onFinish={handleSearch} style={{ marginBottom: 16 }}>
          <Form.Item name="keyword">
            <Input placeholder="文件名 / 替代文本" allowClear style={{ width: 200 }} />
          </Form.Item>
          <Form.Item name="type">
            <Select placeholder="全部类型" allowClear style={{ width: 130 }} options={TYPE_OPTIONS} />
          </Form.Item>
          <Form.Item>
            <Space>
              <Button type="primary" htmlType="submit">
                查询
              </Button>
              <Button
                onClick={() => {
                  searchForm.resetFields();
                  setFilters({ type: '', folder: '', keyword: '' });
                  setPage(1);
                }}
              >
                重置
              </Button>
            </Space>
          </Form.Item>
          {filters.folder && (
            <Form.Item>
              <Tag closable color="blue" onClose={() => pickFolder('')}>
                分组：{filters.folder}
              </Tag>
            </Form.Item>
          )}
        </Form>

        {selectedIds.length > 0 && (
          <div style={{ marginBottom: 12 }}>
            <Space>
              <Typography.Text type="secondary">已选 {selectedIds.length} 项</Typography.Text>
              <Button
                icon={<FolderOutlined />}
                onClick={() => {
                  setMoveTarget(filters.folder || DEFAULT_FOLDER);
                  setMoveOpen(true);
                }}
              >
                移动到分组
              </Button>
              <Popconfirm
                title={`确认删除选中的 ${selectedIds.length} 个素材？`}
                description="被内容引用的素材会被自动跳过"
                okText="确认"
                cancelText="取消"
                onConfirm={handleBatchDelete}
              >
                <Button danger icon={<DeleteOutlined />}>
                  批量删除
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
          locale={{ emptyText: <Empty description="还没有素材，先上传一张图片吧" /> }}
          rowSelection={{
            selectedRowKeys: selectedIds,
            onChange: (keys) => setSelectedIds(keys.map(Number)),
          }}
          pagination={{
            current: page,
            pageSize,
            total,
            showSizeChanger: true,
            showTotal: (t) => `共 ${t} 个素材`,
            onChange: (p, s) => {
              setPage(p);
              setPageSize(s);
            },
          }}
        />
      </Card>

      {/* ---------- 编辑素材 ---------- */}
      <Modal
        title="编辑素材"
        open={!!editRow}
        onCancel={() => setEditRow(null)}
        onOk={handleSaveEdit}
        confirmLoading={saving}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={editForm} layout="vertical" preserve={false}>
          <Form.Item name="name" label="文件名" rules={[{ required: true, message: '请输入文件名' }]}>
            <Input placeholder="用于列表展示的名称" maxLength={200} />
          </Form.Item>
          <Form.Item name="folder" label="分组">
            <AutoComplete
              options={folders.map((item) => ({ label: item.name, value: item.name }))}
              placeholder="选择或输入新分组（如 default、cover）"
              maxLength={30}
              filterOption={(input, option) =>
                String(option?.value ?? '').toLowerCase().includes(String(input).toLowerCase())
              }
            />
          </Form.Item>
          <Form.Item name="alt" label="替代文本">
            <Input placeholder="图片的 alt，利于无障碍与 SEO" maxLength={255} />
          </Form.Item>
        </Form>
      </Modal>

      {/* ---------- 新建 / 重命名分组 ---------- */}
      <Modal
        title={folderModal?.mode === 'create' ? '新建分组' : '重命名分组'}
        open={!!folderModal}
        onCancel={() => setFolderModal(null)}
        onOk={handleSaveFolder}
        confirmLoading={savingFolder}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={folderForm} layout="vertical" preserve={false}>
          <Form.Item
            name="name"
            label="分组名称"
            rules={[
              { required: true, message: '请输入分组名称' },
              { max: 30, message: '分组名称不能超过 30 个字符' },
            ]}
          >
            <Input placeholder="如 default、cover、editor" maxLength={30} />
          </Form.Item>
        </Form>
      </Modal>

      {/* ---------- 批量移动分组 ---------- */}
      <Modal
        title={`将选中的 ${selectedIds.length} 个素材移动到`}
        open={moveOpen}
        onCancel={() => setMoveOpen(false)}
        onOk={handleMove}
        confirmLoading={moving}
        okText="移动"
        cancelText="取消"
      >
        <AutoComplete
          value={moveTarget}
          onChange={setMoveTarget}
          style={{ width: '100%' }}
          options={folders.map((item) => ({ label: `${item.name}（${item.count}）`, value: item.name }))}
          placeholder="选择或输入新分组"
          maxLength={30}
          filterOption={(input, option) =>
            String(option?.value ?? '').toLowerCase().includes(String(input).toLowerCase())
          }
        />
      </Modal>
    </div>
  );
}
