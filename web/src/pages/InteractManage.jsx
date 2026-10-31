import { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Button,
  Card,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Space,
  Statistic,
  Switch,
  Table,
  Tabs,
  Tag,
  message,
} from 'antd';
import { commentAuditApi, pointConfigApi, reportApi, sensitiveApi } from '../api';
import { useAuth } from '../store/AuthContext';

/**
 * 互动管理：评论审核 / 举报处理 / 评论配置 / 敏感词 / 积分规则 / 积分统计
 *
 * 这些功能共用「互动管理」一个菜单，没有各自独立的菜单项，
 * 所以做成一个带标签页的页面，而不是拆成六个路由。
 */
export default function InteractManage() {
  const { hasPermission } = useAuth();

  const items = [
    { key: 'comment', label: '评论审核', children: <CommentAudit /> },
    { key: 'report', label: '举报处理', children: <ReportHandle /> },
    { key: 'config', label: '评论配置', children: <CommentConfig /> },
    { key: 'sensitive', label: '敏感词库', children: <SensitiveWords /> },
    { key: 'points', label: '积分规则', children: <PointRules hasPermission={hasPermission} /> },
    { key: 'stat', label: '积分统计', children: <PointStat /> },
  ];

  return (
    <Card>
      <Tabs defaultActiveKey="comment" items={items} />
    </Card>
  );
}

/* ---------------- 评论审核 ---------------- */
function CommentAudit() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [pending, setPending] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [status, setStatus] = useState(0);
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await commentAuditApi.list({ page, limit: pageSize, status, keyword });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
      setPending(res.data.pending || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, pageSize, status, keyword]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const audit = async (row, result) => {
    try {
      await commentAuditApi.audit(row.id, result);
      message.success(result === 1 ? '已通过' : '已驳回');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const remove = async (row) => {
    try {
      await commentAuditApi.remove(row.id);
      message.success('已删除');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: '评论内容', dataIndex: 'content' },
    { title: '用户', dataIndex: 'nickname', width: 120 },
    { title: '所属内容', dataIndex: 'content_title', width: 180, ellipsis: true },
    { title: 'IP', dataIndex: 'ip', width: 130 },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (v) => {
        const map = { 0: ['orange', '待审核'], 1: ['green', '已通过'], 2: ['red', '已驳回'] };
        const [color, text] = map[v] || ['default', v];
        return <Tag color={color}>{text}</Tag>;
      },
    },
    {
      title: '操作',
      key: 'action',
      width: 180,
      render: (_, row) => (
        <Space size={4}>
          {Number(row.status) !== 1 && (
            <Button type="link" size="small" onClick={() => audit(row, 1)}>
              通过
            </Button>
          )}
          {Number(row.status) !== 2 && (
            <Button type="link" size="small" onClick={() => audit(row, 2)}>
              驳回
            </Button>
          )}
          <Popconfirm title="确认删除该评论？" okText="删除" cancelText="取消" onConfirm={() => remove(row)}>
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
      <Alert type="info" showIcon message={`当前有 ${pending} 条评论待审核`} style={{ marginBottom: 16 }} />
      <Space style={{ marginBottom: 16 }}>
        <Input.Search
          placeholder="评论内容 / 用户昵称"
          allowClear
          style={{ width: 240 }}
          onSearch={(v) => {
            setKeyword(v);
            setPage(1);
          }}
        />
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          style={{ height: 32, borderRadius: 6, border: '1px solid #d9d9d9' }}
        >
          <option value="0">待审核</option>
          <option value="1">已通过</option>
          <option value="2">已驳回</option>
          <option value="">全部</option>
        </select>
      </Space>

      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        columns={columns}
        dataSource={list}
        locale={{ emptyText: <Empty description="没有符合条件的评论" /> }}
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
    </>
  );
}

/* ---------------- 举报处理 ---------------- */
function ReportHandle() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [status, setStatus] = useState(0);
  const [loading, setLoading] = useState(false);
  const [handling, setHandling] = useState(null);
  const [form] = Form.useForm();

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await reportApi.list({ page, limit: pageSize, status });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page, pageSize, status]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const submit = async () => {
    const values = await form.validateFields();
    try {
      await reportApi.handle(handling.id, { result: values.result, handle_result: values.handle_result });
      message.success('已处理');
      setHandling(null);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: '举报对象', dataIndex: 'target_text', ellipsis: true },
    { title: '类型', dataIndex: 'target_type', width: 90, render: (v) => (v === 'comment' ? '评论' : '内容') },
    { title: '理由', dataIndex: 'reason', width: 180, ellipsis: true },
    { title: '举报人', dataIndex: 'nickname', width: 120 },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (v) => {
        const map = { 0: ['orange', '待处理'], 1: ['green', '已处理'], 2: ['default', '已驳回'] };
        const [color, text] = map[v] || ['default', v];
        return <Tag color={color}>{text}</Tag>;
      },
    },
    {
      title: '操作',
      key: 'action',
      width: 100,
      render: (_, row) =>
        Number(row.status) === 0 ? (
          <Button
            type="link"
            size="small"
            onClick={() => {
              setHandling(row);
              form.setFieldsValue({ result: 1, handle_result: '' });
            }}
          >
            处理
          </Button>
        ) : (
          <span style={{ color: '#bfbfbf' }}>已处理</span>
        ),
    },
  ];

  return (
    <>
      <Space style={{ marginBottom: 16 }}>
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          style={{ height: 32, borderRadius: 6, border: '1px solid #d9d9d9' }}
        >
          <option value="0">待处理</option>
          <option value="1">已处理</option>
          <option value="2">已驳回</option>
          <option value="">全部</option>
        </select>
      </Space>

      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        columns={columns}
        dataSource={list}
        locale={{ emptyText: <Empty description="没有符合条件的举报" /> }}
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
        title="处理举报"
        open={!!handling}
        onCancel={() => setHandling(null)}
        onOk={submit}
        okText="提交"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false}>
          <Form.Item name="result" label="处理结果" rules={[{ required: true }]}>
            <select style={{ height: 32, width: '100%', borderRadius: 6, border: '1px solid #d9d9d9' }}>
              <option value="1">举报成立，已处理</option>
              <option value="2">举报不成立，驳回</option>
            </select>
          </Form.Item>
          <Form.Item name="handle_result" label="处理说明">
            <Input.TextArea rows={3} placeholder="会记录到举报单上，便于日后核查" maxLength={200} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}

/* ---------------- 评论配置 ---------------- */
function CommentConfig() {
  const [config, setConfig] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    commentAuditApi
      .config()
      .then((res) => setConfig(res.data))
      .catch(() => {});
  }, []);

  const save = async (patch) => {
    setSaving(true);
    try {
      await commentAuditApi.saveConfig(patch);
      setConfig((prev) => ({ ...prev, ...patch }));
      message.success('已保存');
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSaving(false);
    }
  };

  if (!config) {
    return <Empty description="加载中…" />;
  }

  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <div>
        <span style={{ marginRight: 12 }}>开启评论</span>
        <Switch
          checked={Number(config.enabled) === 1}
          disabled={saving}
          onChange={(v) => save({ enabled: v ? 1 : 0 })}
        />
        <span style={{ color: '#8c8c8c', marginLeft: 12 }}>关闭后前台不展示评论区，也禁止提交</span>
      </div>
      <div>
        <span style={{ marginRight: 12 }}>先审后发</span>
        <Switch
          checked={Number(config.audit_first) === 1}
          disabled={saving}
          onChange={(v) => save({ audit_first: v ? 1 : 0 })}
        />
        <span style={{ color: '#8c8c8c', marginLeft: 12 }}>开启后新评论默认待审，审核通过才可见</span>
      </div>
      <div>
        <span style={{ marginRight: 12 }}>长度限制</span>
        <InputNumber
          min={1}
          value={config.min_length}
          disabled={saving}
          onChange={(v) => setConfig((prev) => ({ ...prev, min_length: v }))}
        />
        <span style={{ margin: '0 8px' }}>-</span>
        <InputNumber
          min={1}
          max={2000}
          value={config.max_length}
          disabled={saving}
          onChange={(v) => setConfig((prev) => ({ ...prev, max_length: v }))}
        />
        <Button style={{ marginLeft: 12 }} disabled={saving} onClick={() => save({ min_length: config.min_length, max_length: config.max_length })}>
          保存
        </Button>
      </div>
    </Space>
  );
}

/* ---------------- 敏感词库 ---------------- */
function SensitiveWords() {
  const [list, setList] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [adding, setAdding] = useState(false);
  const [form] = Form.useForm();

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await sensitiveApi.list({ page, limit: 20 });
      setList(res.data.list || []);
      setTotal(res.data.total || 0);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const submit = async () => {
    const values = await form.validateFields();
    try {
      const res = await sensitiveApi.create(values);
      message.success(res.msg || '已新增');
      setAdding(false);
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const remove = async (row) => {
    try {
      await sensitiveApi.remove(row.id);
      message.success('已删除');
      loadList();
    } catch (e) {
      // 错误提示已由拦截器统一处理
    }
  };

  const columns = [
    { title: '词语', dataIndex: 'word', width: 200 },
    {
      title: '处理级别',
      dataIndex: 'level',
      width: 140,
      render: (v) => {
        const map = { 1: ['blue', '仅提醒'], 2: ['gold', '替换'], 3: ['red', '拦截并转审核'] };
        const [color, text] = map[v] || ['default', v];
        return <Tag color={color}>{text}</Tag>;
      },
    },
    { title: '替换文本', dataIndex: 'replacement', width: 120 },
    {
      title: '操作',
      key: 'action',
      width: 100,
      render: (_, row) => (
        <Popconfirm title="确认删除该敏感词？" okText="删除" cancelText="取消" onConfirm={() => remove(row)}>
          <Button type="link" size="small" danger>
            删除
          </Button>
        </Popconfirm>
      ),
    },
  ];

  return (
    <>
      <Button type="primary" style={{ marginBottom: 16 }} onClick={() => setAdding(true)}>
        新增敏感词
      </Button>
      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        columns={columns}
        dataSource={list}
        pagination={{ current: page, pageSize: 20, total, onChange: setPage }}
        locale={{ emptyText: <Empty description="词库为空" /> }}
      />

      <Modal
        title="新增敏感词"
        open={adding}
        onCancel={() => setAdding(false)}
        onOk={submit}
        okText="提交"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" preserve={false} initialValues={{ level: 2, replacement: '***' }}>
          <Form.Item name="word" label="词语" extra="支持批量：用逗号或空格分隔，一次性可加多个" rules={[{ required: true, message: '请输入词语' }]}>
            <Input.TextArea rows={3} />
          </Form.Item>
          <Form.Item name="level" label="处理级别">
            <select style={{ height: 32, width: '100%', borderRadius: 6, border: '1px solid #d9d9d9' }}>
              <option value="1">仅提醒</option>
              <option value="2">替换</option>
              <option value="3">拦截并转审核</option>
            </select>
          </Form.Item>
          <Form.Item name="replacement" label="替换文本（级别为替换时生效）">
            <Input maxLength={50} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}

/* ---------------- 积分规则 ---------------- */
function PointRules({ hasPermission }) {
  const [rules, setRules] = useState([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    pointConfigApi
      .rules()
      .then((res) => setRules(res.data.list || []))
      .catch(() => {});
  }, []);

  const save = async () => {
    setSaving(true);
    const payload = {};
    rules.forEach((r) => {
      payload[r.key] = r.value;
    });
    try {
      await pointConfigApi.saveRules(payload);
      message.success('规则已保存');
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Space direction="vertical" size={12} style={{ width: '100%' }}>
        {rules.map((rule, index) => (
          <div key={rule.key} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <span style={{ width: 200 }}>{rule.name}</span>
            <InputNumber
              min={0}
              value={Number(rule.value)}
              disabled={!hasPermission('points.rule')}
              onChange={(v) => {
                const next = [...rules];
                next[index] = { ...rule, value: v ?? 0 };
                setRules(next);
              }}
            />
            <span style={{ color: '#8c8c8c' }}>{rule.remark}</span>
          </div>
        ))}
      </Space>
      {hasPermission('points.rule') ? (
        <Button type="primary" style={{ marginTop: 16 }} loading={saving} onClick={save}>
          保存规则
        </Button>
      ) : null}
    </>
  );
}

/* ---------------- 积分统计 ---------------- */
function PointStat() {
  const [data, setData] = useState(null);

  useEffect(() => {
    pointConfigApi
      .stat()
      .then((res) => setData(res.data))
      .catch(() => {});
  }, []);

  if (!data) {
    return <Empty description="加载中…" />;
  }

  return (
    <Space direction="vertical" size={20} style={{ width: '100%' }}>
      <Space size={48}>
        <Statistic title="累计发放" value={data.gain} />
        <Statistic title="累计消耗" value={data.cost} />
      </Space>

      <Table
        rowKey="id"
        size="small"
        pagination={false}
        dataSource={data.topUsers || []}
        locale={{ emptyText: <Empty description="暂无数据" /> }}
        columns={[
          { title: '用户名', dataIndex: 'username' },
          { title: '昵称', dataIndex: 'nickname' },
          { title: '积分', dataIndex: 'points', width: 100 },
          { title: '等级', dataIndex: 'level', width: 80 },
        ]}
      />
    </Space>
  );
}
