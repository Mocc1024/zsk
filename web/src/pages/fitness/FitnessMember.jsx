import { useCallback, useEffect, useState } from 'react';
import {
  Button, Card, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Select,
  Space, Table, Tag, Typography, message, Drawer, List,
} from 'antd';
import { EditOutlined, PlusOutlined, SearchOutlined, UndoOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import {
  fitnessMemberApi, fitnessStoreApi, fitnessEnterpriseApi,
} from '../../api';
import { useAuth } from '../../store/AuthContext';
import { CoverUpload } from '../../components/UploadFile';

const fmt = 'YYYY-MM-DD HH:mm:ss';

/** 全民健身 - 会员（学员）管理 */
export default function FitnessMember() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [consumeForm] = Form.useForm();
  const [cardForm] = Form.useForm();
  const [searchForm] = Form.useForm();

  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10, deleted: 0 });

  const [stores, setStores] = useState([]);
  const [enterprises, setEnterprises] = useState([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const [activeMember, setActiveMember] = useState(null);
  const [consumeOpen, setConsumeOpen] = useState(false);
  const [cardOpen, setCardOpen] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerType, setDrawerType] = useState('consume'); // consume | card
  const [records, setRecords] = useState([]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fitnessMemberApi.list(query);
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } finally { setLoading(false); }
  }, [query]);

  useEffect(() => { fetchList(); }, [fetchList]);
  useEffect(() => {
    fitnessStoreApi.options().then((r) => setStores(r.data || []));
    fitnessEnterpriseApi.options().then((r) => setEnterprises(r.data || []));
  }, []);

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ avatar: '', birth_date: null, store_id: undefined, enterprise_id: undefined });
    setModalOpen(true);
  };

  const openEdit = async (record) => {
    setEditing(record);
    const res = await fitnessMemberApi.detail(record.id);
    const d = res.data;
    form.setFieldsValue({
      name: d.name, mobile: d.mobile, avatar: d.avatar,
      birth_date: d.birth_date ? dayjs(d.birth_date) : null,
      bio: d.bio, store_id: d.store_id,
      enterprise_id: d.enterprise_id || undefined,
    });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      const payload = { ...values, birth_date: values.birth_date ? values.birth_date.format('YYYY-MM-DD') : '' };
      if (editing) {
        await fitnessMemberApi.update(editing.id, payload);
        message.success('保存成功');
      } else {
        const res = await fitnessMemberApi.create(payload);
        message.success(res.msg || '新增成功');
      }
      setModalOpen(false);
      fetchList();
    } finally { setSubmitting(false); }
  };

  const handleDelete = async (id) => {
    await fitnessMemberApi.remove(id);
    message.success('删除成功');
    fetchList();
  };

  const handleRestore = async (id) => {
    await fitnessMemberApi.restore(id);
    message.success('恢复成功');
    fetchList();
  };

  const openConsume = (record) => {
    setActiveMember(record);
    consumeForm.resetFields();
    consumeForm.setFieldsValue({ consume_date: dayjs(), lessons: 1 });
    setConsumeOpen(true);
  };

  const handleConsume = async () => {
    const values = await consumeForm.validateFields();
    const res = await fitnessMemberApi.consume(activeMember.id, {
      consume_date: values.consume_date.format(fmt),
      lessons: values.lessons,
      remark: values.remark,
    });
    message.success(res.msg || '消课成功');
    setConsumeOpen(false);
    fetchList();
  };

  const openCard = (record) => {
    setActiveMember(record);
    cardForm.resetFields();
    cardForm.setFieldsValue({ card_date: dayjs() });
    setCardOpen(true);
  };

  const handleCard = async () => {
    const values = await cardForm.validateFields();
    const res = await fitnessMemberApi.cardCreate(activeMember.id, {
      card_date: values.card_date.format(fmt),
      start_date: values.start_date.format('YYYY-MM-DD'),
      end_date: values.end_date.format('YYYY-MM-DD'),
      amount: values.amount,
    });
    message.success(res.msg || '保存成功');
    setCardOpen(false);
  };

  const openRecords = async (record, type) => {
    setActiveMember(record);
    setDrawerType(type);
    const res = type === 'consume'
      ? await fitnessMemberApi.consumeList(record.id, { limit: 200 })
      : await fitnessMemberApi.cardList(record.id, { limit: 200 });
    setRecords(res.data.list || []);
    setDrawerOpen(true);
  };

  const handleReverseCard = async (id) => {
    const res = await fitnessMemberApi.cardReverse(id);
    message.success(res.msg || '已冲销');
    openRecords(activeMember, 'card');
  };

  const handleConsumeDelete = async (id) => {
    const res = await fitnessMemberApi.consumeDelete(id);
    message.success(res.msg || '已撤销');
    openRecords(activeMember, 'consume');
    fetchList();
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>会员管理</Typography.Title>
        {hasPermission('fitness.member.create') && (
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>新增会员</Button>
        )}
      </div>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={searchForm} layout="inline" initialValues={{ deleted: 0 }}
          onFinish={(v) => setQuery((p) => ({ ...p, page: 1, keyword: v.keyword || undefined, store_id: v.store_id, deleted: v.deleted }))} style={{ rowGap: 12 }}>
          <Form.Item name="keyword"><Input allowClear prefix={<SearchOutlined />} placeholder="姓名 / 手机号" style={{ width: 220 }} /></Form.Item>
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
          rowKey="id" loading={loading} dataSource={list} scroll={{ x: 1340 }}
          pagination={{
            current: query.page, pageSize: query.limit, total, showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery((p) => ({ ...p, page, limit })),
          }}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '姓名', dataIndex: 'name', width: 100 },
            { title: '手机号', dataIndex: 'mobile', width: 130 },
            { title: '出生日期', dataIndex: 'birth_date', width: 120 },
            { title: '门店', dataIndex: 'store_name', width: 120 },
            { title: '教练', dataIndex: 'coach_name', width: 100, render: (v) => v || <span style={{ color: '#999' }}>未绑定</span> },
            { title: '企业', dataIndex: 'enterprise_name', width: 120, render: (v) => v || <span style={{ color: '#999' }}>未绑定</span> },
            {
              title: '课时余额', dataIndex: 'lesson_balance', width: 100, align: 'right',
              render: (v) => <Tag color={Number(v) > 0 ? 'blue' : 'default'}>{v} 次</Tag>,
            },
            {
              title: '状态', dataIndex: 'delete_time', width: 90,
              render: (v) => v ? <Tag color="red">禁用</Tag> : <Tag color="green">启用</Tag>,
            },
            {
              title: '操作', key: 'action', width: 340, fixed: 'right',
              render: (_, record) => (
                <Space size={2} wrap>
                  {record.delete_time ? (hasPermission('fitness.member.delete') && (
                    <Popconfirm title="确认恢复该会员？" onConfirm={() => handleRestore(record.id)}>
                      <Button type="link" size="small" icon={<UndoOutlined />}>恢复</Button>
                    </Popconfirm>
                  )) : (
                    <>
                      {hasPermission('fitness.member.update') && <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>编辑</Button>}
                      {hasPermission('fitness.member.consume') && <>
                        <Button type="link" size="small" onClick={() => openConsume(record)}>消课</Button>
                        <Button type="link" size="small" onClick={() => openRecords(record, 'consume')}>消课记录</Button>
                      </>}
                      {hasPermission('fitness.member.card') && <>
                        <Button type="link" size="small" onClick={() => openCard(record)}>会员卡</Button>
                        <Button type="link" size="small" onClick={() => openRecords(record, 'card')}>办卡记录</Button>
                      </>}
                      {hasPermission('fitness.member.delete') && (
                        <Popconfirm title="确认删除该会员？绑定教练或课时未上完时将被拒绝，同时停用其前台账号" onConfirm={() => handleDelete(record.id)}>
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

      {/* 新增/编辑会员 */}
      <Modal title={editing ? '编辑会员' : '新增会员'} open={modalOpen} onCancel={() => setModalOpen(false)}
        onOk={handleSubmit} confirmLoading={submitting} okText="保存" cancelText="取消" width={600} destroyOnClose>
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="name" label="姓名" rules={[{ required: true, message: '请输入姓名' }]}><Input /></Form.Item>
          <Form.Item name="mobile" label="手机号" rules={[{ required: true, message: '请输入手机号' }, { pattern: /^1\d{10}$/, message: '请输入 11 位手机号' }]}
            extra={editing ? '修改手机号只影响档案，不影响前台登录' : '初始密码为手机号后 6 位'}>
            <Input />
          </Form.Item>
          <Form.Item name="avatar" label="头像"><CoverUpload folder="fitness/avatar" /></Form.Item>
          <Form.Item name="birth_date" label="出生日期"><DatePicker style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="store_id" label="绑定门店" rules={[{ required: true, message: '请选择门店' }]}>
            <Select placeholder="请选择门店" options={stores.map((s) => ({ label: s.name, value: s.id }))} />
          </Form.Item>
          <Form.Item name="enterprise_id" label="绑定企业">
            <Select allowClear placeholder="可选" options={enterprises.map((e) => ({ label: e.name, value: e.id }))} />
          </Form.Item>
          <Form.Item name="bio" label="个人介绍"><Input.TextArea rows={3} /></Form.Item>
        </Form>
      </Modal>

      {/* 消课弹框 */}
      <Modal title={`消课 - ${activeMember?.name || ''}`} open={consumeOpen} onCancel={() => setConsumeOpen(false)}
        onOk={handleConsume} okText="确认消课" cancelText="取消" width={480} destroyOnClose>
        <Form form={consumeForm} layout="vertical" preserve={false}>
          <Form.Item name="consume_date" label="选择日期时间" rules={[{ required: true, message: '请选择日期时间' }]}>
            <DatePicker showTime format={fmt} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="lessons" label="消课次数" rules={[{ required: true, message: '请输入消课次数' }]}
            extra={`当前余额 ${activeMember?.lesson_balance ?? 0} 次`}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} addonAfter="次" />
          </Form.Item>
          <Form.Item name="remark" label="备注"><Input placeholder="选填" /></Form.Item>
        </Form>
      </Modal>

      {/* 会员卡弹框 */}
      <Modal title={`会员卡管理 - ${activeMember?.name || ''}`} open={cardOpen} onCancel={() => setCardOpen(false)}
        onOk={handleCard} okText="保存" cancelText="取消" width={520} destroyOnClose>
        <Form form={cardForm} layout="vertical" preserve={false}>
          <Form.Item name="card_date" label="选择日期时间" rules={[{ required: true, message: '请选择日期时间' }]}>
            <DatePicker showTime format={fmt} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="start_date" label="有效期开始" rules={[{ required: true, message: '请选择开始日期' }]}>
            <DatePicker style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="end_date" label="有效期结束" rules={[{ required: true, message: '请选择结束日期' }]}>
            <DatePicker style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="amount" label="金额" rules={[{ required: true, message: '请输入金额' }]}>
            <InputNumber min={0.01} precision={2} style={{ width: '100%' }} prefix="¥" />
          </Form.Item>
        </Form>
      </Modal>

      {/* 记录抽屉 */}
      <Drawer
        title={drawerType === 'consume' ? `消课记录 - ${activeMember?.name || ''}` : `办卡记录 - ${activeMember?.name || ''}`}
        open={drawerOpen} onClose={() => setDrawerOpen(false)} width={640}
      >
        <List
          dataSource={records}
          locale={{ emptyText: '暂无记录' }}
          renderItem={(item) => (
            <List.Item actions={[
              drawerType === 'consume'
                ? hasPermission('fitness.member.consume') && (
                    <Popconfirm title="撤销该消课记录并返还课时？" onConfirm={() => handleConsumeDelete(item.id)}>
                      <Button type="link" size="small" danger>撤销</Button>
                    </Popconfirm>
                  )
                : (item.reverse_of > 0 || Number(item.amount) < 0)
                  ? <Tag>已冲销</Tag>
                  : hasPermission('fitness.member.card') && (
                      <Popconfirm title="确认冲销该办卡记录？将按负数计入业绩" onConfirm={() => handleReverseCard(item.id)}>
                        <Button type="link" size="small" danger>冲销</Button>
                      </Popconfirm>
                    ),
            ]}>
              <List.Item.Meta
                title={drawerType === 'consume'
                  ? <span>{item.consume_date} · 消耗 {item.lessons} 次</span>
                  : <span>¥{Number(item.amount).toFixed(2)} · 有效期 {item.start_date} 至 {item.end_date}</span>}
                description={drawerType === 'consume'
                  ? `录入：${item.create_time}${item.remark ? '　备注：' + item.remark : ''}`
                  : `办卡日期：${item.card_date}　录入：${item.create_time}`}
              />
            </List.Item>
          )}
        />
      </Drawer>
    </div>
  );
}
