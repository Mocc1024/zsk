import { useCallback, useEffect, useState } from 'react';
import {
  Button, Card, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Select,
  Space, Table, Tag, Typography, Upload, message, Drawer, List, Transfer, Image,
} from 'antd';
import { EditOutlined, PlusOutlined, SearchOutlined, InboxOutlined, UndoOutlined, ExportOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { fitnessCoachApi, fitnessStoreApi, uploadApi } from '../../api';
import { useAuth } from '../../store/AuthContext';
import { CoverUpload } from '../../components/UploadFile';
import { downloadExport } from '../../utils/export';

const fmt = 'YYYY-MM-DD HH:mm:ss';

/** 证书照片多图上传 */
function CertificateUpload({ value = [], onChange }) {
  const [uploading, setUploading] = useState(false);
  return (
    <div>
      <Upload
        listType="picture-card"
        showUploadList={false}
        accept=".jpg,.jpeg,.png,.gif,.webp,.bmp"
        beforeUpload={async (file) => {
          if (file.size > 5 * 1024 * 1024) { message.error('图片不能超过 5MB'); return false; }
          const fd = new FormData();
          fd.append('file', file);
          fd.append('folder', 'fitness/certificate');
          setUploading(true);
          try {
            const res = await uploadApi.image(fd);
            onChange([...(value || []), res.data.url]);
            message.success('证书照片已上传');
          } catch (e) { /* 拦截器已提示 */ }
          finally { setUploading(false); }
          return false;
        }}
      >
        <div><InboxOutlined /><div style={{ marginTop: 4 }}>{uploading ? '上传中' : '上传'}</div></div>
      </Upload>
      <Space wrap size={8} style={{ marginTop: 8 }}>
        {(value || []).map((url, idx) => (
          <div key={`${url}-${idx}`} style={{ position: 'relative' }}>
            <Image src={url} width={80} height={80} style={{ objectFit: 'cover', borderRadius: 6 }} />
            <Button
              type="link" size="small" danger
              style={{ position: 'absolute', top: -6, right: -6, padding: 0, background: '#fff', borderRadius: '50%' }}
              onClick={() => onChange(value.filter((_, i) => i !== idx))}
            >×</Button>
          </div>
        ))}
      </Space>
    </div>
  );
}

/** 全民健身 - 教练管理 */
export default function FitnessCoach() {
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();
  const [perfForm] = Form.useForm();
  const [bindForm] = Form.useForm();
  const [searchForm] = Form.useForm();

  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [query, setQuery] = useState({ page: 1, limit: 10, status: 1 });

  const [stores, setStores] = useState([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const [bindOpen, setBindOpen] = useState(false);
  const [bindCoach, setBindCoach] = useState(null);
  const [bindMode, setBindMode] = useState('store'); // store | students
  const [studentPool, setStudentPool] = useState([]);
  const [selectedStudents, setSelectedStudents] = useState([]);

  const [perfOpen, setPerfOpen] = useState(false);
  const [perfCoach, setPerfCoach] = useState(null);
  const [perfList, setPerfList] = useState([]);
  const [perfDrawerOpen, setPerfDrawerOpen] = useState(false);
  const [perfStudents, setPerfStudents] = useState([]);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fitnessCoachApi.list(query);
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } finally { setLoading(false); }
  }, [query]);

  useEffect(() => { fetchList(); }, [fetchList]);
  useEffect(() => { fitnessStoreApi.options().then((r) => setStores(r.data || [])); }, []);

  const fetchPerfList = useCallback(async (coachId) => {
    const res = await fitnessCoachApi.performanceList(coachId, { limit: 200 });
    setPerfList(res.data.list || []);
  }, []);

  const loadStudents = async (coachId = 0) => {
    const res = await fitnessCoachApi.students({ keyword: '' });
    const rows = res.data || [];
    setStudentPool(rows.map((m) => {
      const boundByOther = Number(m.coach_id) > 0 && Number(m.coach_id) !== Number(coachId);
      return {
        key: m.id,
        title: `${m.name}（${m.mobile || '无手机号'}）${boundByOther ? ` · 已属${m.coach_name || '其他教练'}` : ''}`,
        disabled: boundByOther,
      };
    }));
    return rows;
  };

  const openCreate = () => {
    setEditing(null);
    form.resetFields();
    form.setFieldsValue({ certificates: [], avatar: '', birth_date: null });
    setModalOpen(true);
  };

  const openEdit = async (record) => {
    setEditing(record);
    const res = await fitnessCoachApi.detail(record.id);
    const d = res.data;
    form.setFieldsValue({
      name: d.name, mobile: d.mobile, avatar: d.avatar,
      birth_date: d.birth_date ? dayjs(d.birth_date) : null,
      bio: d.bio, certificates: d.certificates || [],
    });
    setModalOpen(true);
  };

  const handleSubmit = async () => {
    const values = await form.validateFields();
    setSubmitting(true);
    try {
      const payload = {
        ...values,
        birth_date: values.birth_date ? values.birth_date.format('YYYY-MM-DD') : '',
      };
      if (editing) {
        await fitnessCoachApi.update(editing.id, payload);
        message.success('保存成功');
      } else {
        const res = await fitnessCoachApi.create(payload);
        message.success(res.msg || '加入成功');
      }
      setModalOpen(false);
      fetchList();
    } finally { setSubmitting(false); }
  };

  const openBind = async (record, mode) => {
    setBindCoach(record);
    setBindMode(mode);
    bindForm.resetFields();
    if (mode === 'store') {
      bindForm.setFieldsValue({ store_id: record.store_id || undefined });
    } else {
      const rows = await loadStudents(record.id);
      const bound = (rows || []).filter((m) => Number(m.coach_id) === Number(record.id)).map((m) => m.id);
      bindForm.setFieldsValue({ member_ids: bound });
      setSelectedStudents(bound);
    }
    setBindOpen(true);
  };

  const handleBind = async () => {
    if (bindMode === 'store') {
      const values = await bindForm.validateFields();
      await fitnessCoachApi.bindStore(bindCoach.id, values.store_id);
    } else {
      const values = await bindForm.validateFields();
      await fitnessCoachApi.bindStudents(bindCoach.id, values.member_ids || []);
    }
    message.success('绑定成功');
    setBindOpen(false);
    fetchList();
  };

  const openPerf = async (record) => {
    setPerfCoach(record);
    perfForm.resetFields();
    perfForm.setFieldsValue({ perf_date: dayjs() });
    const res = await fitnessCoachApi.boundStudents(record.id);
    setPerfStudents(res.data || []);
    setPerfOpen(true);
  };

  const handlePerf = async () => {
    const values = await perfForm.validateFields();
    const res = await fitnessCoachApi.performance(perfCoach.id, {
      member_id: values.member_id,
      perf_date: values.perf_date.format(fmt),
      amount: values.amount,
      lessons: values.lessons,
    });
    message.success(res.msg || '保存成功');
    setPerfOpen(false);
    fetchPerfList(perfCoach.id);
  };

  const handleResign = async (record) => {
    const res = await fitnessCoachApi.resign(record.id);
    message.success(res.msg || '离职办理成功');
    fetchList();
  };

  const handleRestore = async (id) => {
    const res = await fitnessCoachApi.restore(id);
    message.success(res.msg || '恢复成功');
    fetchList();
  };

  const handleReverse = async (id) => {
    const res = await fitnessCoachApi.performanceReverse(id);
    message.success(res.msg || '已冲销');
    fetchPerfList(perfCoach.id);
  };

  const handleExportPerf = async () => {
    if (!perfCoach) return;
    const res = await fitnessCoachApi.performanceExport(perfCoach.id);
    downloadExport(res, '绩效记录.csv');
    message.success(`已导出 ${res.data?.count ?? 0} 条记录`);
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>教练管理</Typography.Title>
        {hasPermission('fitness.coach.create') && (
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>加入教练</Button>
        )}
      </div>

      <Card bordered={false} style={{ marginBottom: 16 }}>
        <Form form={searchForm} layout="inline" initialValues={{ status: 1 }}
          onFinish={(v) => setQuery((p) => ({ ...p, page: 1, keyword: v.keyword || undefined, store_id: v.store_id, status: v.status }))} style={{ rowGap: 12 }}>
          <Form.Item name="keyword"><Input allowClear prefix={<SearchOutlined />} placeholder="姓名 / 手机号" style={{ width: 220 }} /></Form.Item>
          <Form.Item name="store_id">
            <Select allowClear placeholder="全部门店" style={{ width: 170 }}
              options={stores.map((s) => ({ label: s.name, value: s.id }))} />
          </Form.Item>
          <Form.Item name="status">
            <Select placeholder="状态" style={{ width: 130 }}
              options={[{ label: '在职', value: 1 }, { label: '离职', value: 2 }]} />
          </Form.Item>
          <Form.Item><Space>
            <Button type="primary" htmlType="submit">查询</Button>
            <Button onClick={() => { searchForm.resetFields(); setQuery({ page: 1, limit: query.limit, status: 1 }); }}>重置</Button>
          </Space></Form.Item>
        </Form>
      </Card>

      <Card bordered={false}>
        <Table
          rowKey="id" loading={loading} dataSource={list} scroll={{ x: 1150 }}
          pagination={{
            current: query.page, pageSize: query.limit, total, showTotal: (t) => `共 ${t} 条`,
            onChange: (page, limit) => setQuery((p) => ({ ...p, page, limit })),
          }}
          columns={[
            { title: 'ID', dataIndex: 'id', width: 70 },
            { title: '姓名', dataIndex: 'name', width: 110 },
            { title: '手机号', dataIndex: 'mobile', width: 130 },
            { title: '出生日期', dataIndex: 'birth_date', width: 120 },
            { title: '门店', dataIndex: 'store_name', width: 130 },
            { title: '学员数', dataIndex: 'student_count', width: 90, align: 'right' },
            {
              title: '证书', dataIndex: 'certificates', width: 90,
              render: (urls) => <Image.PreviewGroup>{(urls || []).slice(0, 3).map((u, i) => <Image key={i} src={u} width={36} height={36} style={{ objectFit: 'cover', borderRadius: 4, marginRight: 4 }} />)}</Image.PreviewGroup>,
            },
            {
              title: '状态', dataIndex: 'status', width: 90,
              render: (v) => <Tag color={v === 1 ? 'green' : 'default'}>{v === 1 ? '在职' : '离职'}</Tag>,
            },
            {
              title: '操作', key: 'action', width: 320, fixed: 'right',
              render: (_, record) => {
                const active = Number(record.status) === 1;
                return (
                  <Space size={2} wrap>
                    {hasPermission('fitness.coach.update') && <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEdit(record)}>编辑</Button>}
                    {active && hasPermission('fitness.coach.bind-store') && <Button type="link" size="small" onClick={() => openBind(record, 'store')}>绑定门店</Button>}
                    {active && hasPermission('fitness.coach.bind-students') && <Button type="link" size="small" onClick={() => openBind(record, 'students')}>绑定学员</Button>}
                    {active && hasPermission('fitness.coach.performance') && <>
                      <Button type="link" size="small" onClick={() => openPerf(record)}>绩效管理</Button>
                      <Button type="link" size="small" onClick={async () => { setPerfCoach(record); await fetchPerfList(record.id); setPerfDrawerOpen(true); }}>绩效记录</Button>
                    </>}
                    {active && hasPermission('fitness.coach.resign') && (
                      <Popconfirm title="确认办理离职？名下存在学员时将被拒绝，请先解绑" onConfirm={() => handleResign(record)}>
                        <Button type="link" size="small" danger>离职</Button>
                      </Popconfirm>
                    )}
                    {!active && hasPermission('fitness.coach.resign') && (
                      <Popconfirm title="确认恢复该教练为在职？" onConfirm={() => handleRestore(record.id)}>
                        <Button type="link" size="small" icon={<UndoOutlined />}>恢复</Button>
                      </Popconfirm>
                    )}
                  </Space>
                );
              },
            },
          ]}
        />
      </Card>

      {/* 新增/编辑教练 */}
      <Modal title={editing ? '编辑教练' : '加入教练'} open={modalOpen} onCancel={() => setModalOpen(false)}
        onOk={handleSubmit} confirmLoading={submitting} okText="保存" cancelText="取消" width={620} destroyOnClose>
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="name" label="姓名" rules={[{ required: true, message: '请输入姓名' }]}><Input /></Form.Item>
          <Form.Item name="mobile" label="手机号" rules={[{ required: true, message: '请输入手机号' }, { pattern: /^1\d{10}$/, message: '请输入 11 位手机号' }]}
            extra={editing ? '修改手机号只影响档案，不影响前台登录' : '初始密码为手机号后 6 位'}>
            <Input />
          </Form.Item>
          <Form.Item name="avatar" label="头像"><CoverUpload folder="fitness/avatar" /></Form.Item>
          <Form.Item name="birth_date" label="出生日期"><DatePicker style={{ width: '100%' }} picker="date" /></Form.Item>
          {!editing && (
            <Form.Item name="store_id" label="绑定门店" rules={[{ required: true, message: '请选择门店' }]}>
              <Select placeholder="请选择门店" options={stores.map((s) => ({ label: s.name, value: s.id }))} />
            </Form.Item>
          )}
          <Form.Item name="bio" label="个人介绍"><Input.TextArea rows={3} /></Form.Item>
          <Form.Item name="certificates" label="证书照片"><CertificateUpload /></Form.Item>
        </Form>
      </Modal>

      {/* 绑定门店 / 绑定学员 */}
      <Modal title={bindMode === 'store' ? '绑定门店' : '绑定学员'} open={bindOpen} onCancel={() => setBindOpen(false)}
        onOk={handleBind} okText="保存" cancelText="取消" width={bindMode === 'store' ? 480 : 860} destroyOnClose>
        <Form form={bindForm} layout="vertical" preserve={false}>
          {bindMode === 'store' ? (
            <Form.Item name="store_id" label="门店" rules={[{ required: true, message: '请选择门店' }]}>
              <Select placeholder="请选择门店" options={stores.map((s) => ({ label: s.name, value: s.id }))} />
            </Form.Item>
          ) : (
            <Form.Item name="member_ids" label="学员列表（一个学员只能绑定一个教练）">
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
          )}
        </Form>
      </Modal>

      {/* 绩效管理弹框（必填，单选学员） */}
      <Modal title="绩效管理" open={perfOpen} onCancel={() => setPerfOpen(false)}
        onOk={handlePerf} okText="保存" cancelText="取消" width={520} destroyOnClose>
        <Form form={perfForm} layout="vertical" preserve={false}>
          <Form.Item name="perf_date" label="选择日期时间" rules={[{ required: true, message: '请选择日期时间' }]}>
            <DatePicker showTime format={fmt} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="member_id" label="选择学员（仅该教练名下已绑定学员）"
            extra={perfStudents.length === 0 ? '该教练暂未绑定学员，请先在「绑定学员」中绑定' : undefined}
            rules={[{ required: true, message: '请选择学员' }]}>
            <Select placeholder="请选择学员" showSearch disabled={perfStudents.length === 0}
              options={perfStudents.map((m) => ({ label: `${m.name}（${m.mobile || '无手机号'}）`, value: m.id }))} />
          </Form.Item>
          <Form.Item name="amount" label="金额" rules={[{ required: true, message: '请输入金额' }]}>
            <InputNumber min={0.01} precision={2} style={{ width: '100%' }} prefix="¥" />
          </Form.Item>
          <Form.Item name="lessons" label="课时（次数）" rules={[{ required: true, message: '请输入课时次数' }]}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} addonAfter="次" />
          </Form.Item>
        </Form>
      </Modal>

      {/* 绩效记录抽屉 */}
      <Drawer title={`绩效记录 - ${perfCoach?.name || ''}`} open={perfDrawerOpen} onClose={() => setPerfDrawerOpen(false)} width={720}
        extra={hasPermission('fitness.coach.performance') && (
          <Button size="small" icon={<ExportOutlined />} onClick={handleExportPerf}>导出Excel</Button>
        )}>
        <List
          dataSource={perfList}
          locale={{ emptyText: '暂无绩效记录' }}
          renderItem={(item) => (
            <List.Item actions={[item.reverse_of > 0 || Number(item.amount) < 0 ? (
              <Tag>已冲销</Tag>
            ) : hasPermission('fitness.coach.performance') && (
              <Popconfirm title="确认冲销？将同步扣回该学员课时，余额不足时会被拒绝" onConfirm={() => handleReverse(item.id)}>
                <Button type="link" size="small" danger>冲销</Button>
              </Popconfirm>
            )]}>
              <List.Item.Meta
                title={<span>{item.member_name} · ¥{Number(item.amount).toFixed(2)} · {item.lessons} 次</span>}
                description={`门店：${item.store_name || '未知门店'}　日期：${item.perf_date}　录入：${item.create_time}${item.reverse_of ? `　冲销记录 #${item.id}` : ''}`}
              />
            </List.Item>
          )}
        />
      </Drawer>
    </div>
  );
}
