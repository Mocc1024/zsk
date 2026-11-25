import { useCallback, useEffect, useState } from 'react';
import {
  Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Transfer,
  Typography, message,
} from 'antd';
import { EditOutlined, PlusOutlined, SearchOutlined, UndoOutlined, ExportOutlined } from '@ant-design/icons';
import { fitnessEnterpriseApi, fitnessStoreApi, fitnessMemberApi } from '../../api';
import { useAuth } from '../../store/AuthContext';
import { downloadExport } from '../../utils/export';

/** 全民健身 - 企业管理 */
export default function FitnessEnterprise() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [bindForm] = Form.useForm();
  const [searchForm] = Form.useForm();

  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10, deleted: 0 });

  const [stores, setStores] = useState([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const [bindOpen, setBindOpen] = useState(false);
  const [bindEnterprise, setBindEnterprise] = useState(null);
  const [studentPool, setStudentPool] = useState([]);
  const [selectedStudents, setSelectedStudents] = useState([]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fitnessEnterpriseApi.list(query);
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } finally { setLoading(false); }
  }, [query]);

  useEffect(() => { fetchList(); }, [fetchList]);
  useEffect(() => { fitnessStoreApi.options().then((r) => setStores(r.data || [])); }, []);

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ store_id: undefined });
    setModalOpen(true);
  };

  const openEdit = async (record) => {
    setEditing(record);
    const res = await fitnessEnterpriseApi.detail(record.id);
    const d = res.data;
    form.setFieldsValue({
      name: d.name, intro: d.intro, guild_name: d.guild_name,
      guild_intro: d.guild_intro, store_id: d.store_id,
    });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      if (editing) {
        await fitnessEnterpriseApi.update(editing.id, values);
        message.success('保存成功');
      } else {
        await fitnessEnterpriseApi.create(values);
        message.success('新增成功');
      }
      setModalOpen(false);
      fetchList();
    } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    await fitnessEnterpriseApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const handleRestore = async (id) => {
    await fitnessEnterpriseApi.restore(id);
    message.success('恢复成功');
    fetchList();
  };

  const handleExportList = async () => {
    const params = { ...query };
    delete params.page;
    delete params.limit;
    const res = await fitnessEnterpriseApi.exportList(params);
    downloadExport(res, '企业列表.csv');
    message.success(`已导出 ${res.data?.count ?? 0} 条记录`);
  };

  const openBind = async (record) => {
    setBindEnterprise(record);
    bindForm.resetFields();
    const poolRes = await fitnessMemberApi.list({ limit: 200 });
    const rows = poolRes.data.list || [];
    setStudentPool(rows.map((m) => {
      const boundByOther = Number(m.enterprise_id) > 0 && Number(m.enterprise_id) !== Number(record.id);
      return {
        key: m.id,
        title: `${m.name}（${m.mobile || '无手机号'}）${boundByOther ? ` · 已属${m.enterprise_name || '其他企业'}` : ''}`,
        disabled: boundByOther,
      };
    }));
    const bound = rows.filter((m) => Number(m.enterprise_id) === Number(record.id)).map((m) => m.id);
    setSelectedStudents(bound);
    bindForm.setFieldsValue({ member_ids: bound });
    setBindOpen(true);
  };

  const handleBind = async () => {
    const values = await bindForm.validateFields();
    await fitnessEnterpriseApi.bindStudents(bindEnterprise.id, values.member_ids || []);
    message.success('绑定成功');
    setBindOpen(false);
    fetchList();
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>企业管理</Typography.Title>
        <Space>
          {hasPermission('fitness.enterprise.list') && (
            <Button icon={<ExportOutlined />} onClick={handleExportList}>导出Excel</Button>
          )}
          {hasPermission('fitness.enterprise.create') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>新增企业</Button>
          )}
        </Space>
      </div>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={searchForm} layout="inline" initialValues={{ deleted: 0 }}
          onFinish={(v) => setQuery((p) => ({ ...p, page: 1, keyword: v.keyword || undefined, store_id: v.store_id, deleted: v.deleted }))} style={{ rowGap: 12 }}>
          <Form.Item name="keyword"><Input allowClear prefix={<SearchOutlined />} placeholder="企业名称 / 公会名称" style={{ width: 240 }} /></Form.Item>
          <Form.Item name="store_id">
            <Select allowClear placeholder="全部门店" style={{ width: 170 }}
              options={stores.map((s) => ({ label: s.name, value: s.id }))} />
          </Form.Item>
          <Form.Item name="deleted">
            <Select placeholder="状态" style={{ width: 130 }}
              options={[{ label: '启用', value: 0 }, { label: '禁用', value: 1 }]} />
          </Form.Item>
          <Form.Item><Space>
            <Button type="primary" htmlType="submit">查询</Button>
            <Button onClick={() => { searchForm.resetFields(); setQuery({ page: 1, limit: query.limit, deleted: 0 }); }}>重置</Button>
          </Space></Form.Item>
        </Form>
      </Card>

      <Card bordered={false}>
        <Table
          rowKey="id" loading={loading} dataSource={list} scroll={{ x: 1140 }}
          pagination={{
            current: query.page, pageSize: query.limit, total, showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery((p) => ({ ...p, page, limit })),
          }}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '企业名称', dataIndex: 'name', width: 160 },
            { title: '门店', dataIndex: 'store_name', width: 130 },
            { title: '公会名称', dataIndex: 'guild_name', width: 130 },
            { title: '学员数', dataIndex: 'student_count', width: 90, align: 'right' },
            { title: '企业介绍', dataIndex: 'intro', ellipsis: true },
            {
              title: '状态', dataIndex: 'delete_time', width: 90,
              render: (v) => v ? <Tag color="red">禁用</Tag> : <Tag color="green">启用</Tag>,
            },
            {
              title: '操作', key: 'action', width: 220, fixed: 'right',
              render: (_, record) => (
                <Space size={4}>
                  {record.delete_time ? (hasPermission('fitness.enterprise.delete') && (
                    <Popconfirm title="确认恢复该企业？" onConfirm={() => handleRestore(record.id)}>
                      <Button type="link" size="small" icon={<UndoOutlined />}>恢复</Button>
                    </Popconfirm>
                  )) : (
                    <>
                      {hasPermission('fitness.enterprise.update') && <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>编辑</Button>}
                      {hasPermission('fitness.enterprise.bind-students') && <Button type="link" size="small" onClick={() => openBind(record)}>绑定学员</Button>}
                      {hasPermission('fitness.enterprise.delete') && (
                        <Popconfirm title="确认删除该企业？名下存在绑定学员时将被拒绝" onConfirm={() => handleDelete(record.id)}>
                          <Button type="link" size="small" danger>删除</Button>
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

      <Modal title={editing ? '编辑企业' : '新增企业'} open={modalOpen} onCancel={() => setModalOpen(false)}
        onOk={handleSubmit} confirmLoading={submitting} okText="保存" cancelText="取消" width={600} destroyOnClose>
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="name" label="企业名称" rules={[{ required: true, message: '请输入企业名称' }]}><Input /></Form.Item>
          <Form.Item name="store_id" label="绑定门店" rules={[{ required: true, message: '请选择门店' }]}>
            <Select placeholder="请选择门店" options={stores.map((s) => ({ label: s.name, value: s.id }))} />
          </Form.Item>
          <Form.Item name="intro" label="企业介绍"><Input.TextArea rows={3} /></Form.Item>
          <Form.Item name="guild_name" label="公会名称"><Input /></Form.Item>
          <Form.Item name="guild_intro" label="公会介绍"><Input.TextArea rows={3} /></Form.Item>
        </Form>
      </Modal>

      <Modal title={`绑定学员 - ${bindEnterprise?.name || ''}`} open={bindOpen} onCancel={() => setBindOpen(false)}
        onOk={handleBind} okText="保存" cancelText="取消" width={860} destroyOnClose>
        <Form form={bindForm} layout="vertical" preserve={false}>
          <Form.Item name="member_ids" label="学员列表（一个学员只能绑定一个企业）">
            <Transfer
              dataSource={studentPool}
              targetKeys={selectedStudents}
              onChange={(keys) => { setSelectedStudents(keys); bindForm.setFieldsValue({ member_ids: keys }); }}
              render={(item) => item.title}
              listStyle={{ width: 360, height: 420 }}
              titles={['可绑定', '已绑定']}
              showSearch
            />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  );
}
