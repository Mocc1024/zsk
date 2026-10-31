import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Alert,
  Button,
  Card,
  DatePicker,
  Form,
  Input,
  InputNumber,
  Radio,
  Select,
  Space,
  Spin,
  Switch,
  Tag,
  Typography,
  message,
} from 'antd';
import { ArrowLeftOutlined, SaveOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { categoryApi, contentApi, tagApi } from '../api';
import { useAuth } from '../store/AuthContext';
import { CONTENT_STATUS } from '../utils/constants';
import RichEditor from '../components/RichEditor';
import { CoverUpload } from '../components/UploadFile';

/** 自动保存间隔（毫秒） */
const AUTOSAVE_INTERVAL = 60000;

export default function ContentEdit() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [form] = Form.useForm();

  // 有 update 权限不代表能改状态/置顶，这两个字段各自有独立权限点
  const canSetStatus = hasPermission('content.status');
  const canSetTop = hasPermission('content.top');
  const canSave = id ? hasPermission('content.update') : hasPermission('content.create');

  const [categories, setCategories] = useState([]);
  const [tagOptions, setTagOptions] = useState([]);
  const [loading, setLoading] = useState(Boolean(id));
  const [submitting, setSubmitting] = useState(false);

  // 自动保存：新建时自动保存会先落一条草稿，草稿 ID 记在这里
  const draftIdRef = useRef(id ? Number(id) : 0);
  const dirtyRef = useRef(false);
  const [autoSaving, setAutoSaving] = useState(false);
  const [lastSaved, setLastSaved] = useState('');

  useEffect(() => {
    // 连同禁用分类一起取：内容当前所属的可能是禁用分类，
    // 若下拉里没有该项会回显成空白。禁用项在下面置灰、不允许选择。
    categoryApi.options({ include_disabled: 1 }).then((res) => setCategories(res.data || []));
  }, []);

  useEffect(() => {
    tagApi.options().then((res) => {
      const names = (res.data || []).map((item) => item.value);
      setTagOptions(names);
    });
  }, []);

  useEffect(() => {
    if (!id) return;
    setLoading(true);
    contentApi
      .detail(id)
      .then((res) => {
        const data = res.data || {};
        form.setFieldsValue({
          ...data,
          publish_time: data.publish_time ? dayjs(data.publish_time) : null,
        });
      })
      .finally(() => setLoading(false));
  }, [id, form]);

  /** 表单数据 → 接口数据 */
  const buildPayload = (values, withMeta = true) => {
    const payload = {
      ...values,
      tags: values.tags || [],
      content: values.content || '',
      publish_time: values.publish_time
        ? dayjs(values.publish_time).format('YYYY-MM-DD HH:mm:ss')
        : null,
    };
    if (!withMeta) {
      // 自动保存不碰发布状态与置顶，避免绕过字段级权限
      delete payload.status;
      delete payload.is_top;
    }
    return payload;
  };

  const doAutosave = useCallback(async () => {
    if (!dirtyRef.current || !canSave) return;

    const values = form.getFieldsValue(true);
    if (!values.title || !values.title.trim()) return;

    setAutoSaving(true);
    try {
      const res = await contentApi.autosave({
        ...buildPayload(values, false),
        id: draftIdRef.current || undefined,
      });
      if (res.data?.id) {
        draftIdRef.current = Number(res.data.id);
      }
      dirtyRef.current = false;
      setLastSaved(res.data?.saved_at || '');
    } catch (e) {
      // 自动保存失败不打断用户编辑，仅静默重试
    } finally {
      setAutoSaving(false);
    }
  }, [canSave, form]);

  // 定时自动保存
  useEffect(() => {
    if (!canSave) return undefined;
    const timer = setInterval(doAutosave, AUTOSAVE_INTERVAL);
    return () => clearInterval(timer);
  }, [canSave, doAutosave]);

  const handleSubmit = async () => {
    const values = await form.validateFields();
    const payload = buildPayload(values);
    const targetId = id || draftIdRef.current;

    setSubmitting(true);
    try {
      if (targetId) {
        await contentApi.update(targetId, payload);
        message.success('保存成功');
      } else {
        await contentApi.create(payload);
        message.success('新增成功');
      }
      dirtyRef.current = false;
      navigate('/content');
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 120 }}>
        <Spin size="large" />
      </div>
    );
  }

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Space>
          <Typography.Title level={4} style={{ margin: 0 }}>
            {id ? '编辑内容' : '新建内容'}
          </Typography.Title>
          {autoSaving && <Tag color="processing">自动保存中…</Tag>}
          {!autoSaving && lastSaved && <Tag color="success">已自动保存 {lastSaved}</Tag>}
        </Space>
        <Space>
          <Button icon={<ArrowLeftOutlined />} onClick={() => navigate('/content')}>
            返回列表
          </Button>
          <Button
            type="primary"
            icon={<SaveOutlined />}
            loading={submitting}
            disabled={!canSave}
            onClick={handleSubmit}
          >
            保存
          </Button>
        </Space>
      </div>

      <Card bordered={false}>
        {!canSave && (
          <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 16 }}
            message="当前账号没有该操作权限，无法保存"
          />
        )}

        <Form
          form={form}
          layout="vertical"
          initialValues={{
            status: 0,
            is_top: false,
            sort: 0,
            views: 0,
            tags: [],
            content: '',
          }}
          style={{ maxWidth: 900 }}
          onValuesChange={() => {
            dirtyRef.current = true;
          }}
        >
          <Form.Item name="title" label="内容标题" rules={[{ required: true, message: '请输入内容标题' }]}>
            <Input placeholder="请输入标题" maxLength={200} showCount />
          </Form.Item>

          <Space size={24} wrap style={{ display: 'flex' }}>
            <Form.Item name="category_id" label="所属分类" rules={[{ required: true, message: '请选择分类' }]}>
              <Select
                placeholder="请选择分类"
                style={{ width: 240 }}
                options={categories.map((item) => {
                  const disabled = Number(item.status) !== 1;
                  return {
                    // 已选中的禁用分类仍会正常回显名称（antd 只对交互禁用，不影响回显）
                    label: disabled ? `${item.name}（已禁用）` : item.name,
                    value: item.id,
                    disabled,
                  };
                })}
              />
            </Form.Item>
            <Form.Item name="author" label="作者">
              <Input placeholder="请输入作者" style={{ width: 200 }} />
            </Form.Item>
            <Form.Item name="tags" label="标签">
              <Select
                mode="tags"
                placeholder="输入后回车即可新建标签"
                style={{ width: 320 }}
                maxCount={10}
                options={tagOptions.map((name) => ({ label: name, value: name }))}
              />
            </Form.Item>
          </Space>

          <Form.Item name="cover" label="封面图">
            <CoverUpload />
          </Form.Item>

          <Form.Item name="summary" label="内容摘要">
            <Input.TextArea rows={3} maxLength={500} showCount placeholder="一句话描述这篇内容" />
          </Form.Item>

          <Form.Item name="content" label="正文内容">
            <RichEditor height={460} />
          </Form.Item>

          <Space size={24} wrap style={{ display: 'flex' }}>
            {canSetStatus && (
              <Form.Item name="status" label="发布状态">
                <Radio.Group
                  optionType="button"
                  buttonStyle="solid"
                  options={CONTENT_STATUS.map((item) => ({ label: item.label, value: item.value }))}
                />
              </Form.Item>
            )}
            <Form.Item name="publish_time" label="发布时间">
              <DatePicker showTime format="YYYY-MM-DD HH:mm:ss" placeholder="留空则发布时自动填充" />
            </Form.Item>
            <Form.Item name="sort" label="排序值">
              <InputNumber min={0} max={9999} />
            </Form.Item>
            <Form.Item name="views" label="浏览量">
              <InputNumber min={0} />
            </Form.Item>
            {canSetTop && (
              <Form.Item name="is_top" label="是否置顶" valuePropName="checked">
                <Switch checkedChildren="是" unCheckedChildren="否" />
              </Form.Item>
            )}
          </Space>
        </Form>
      </Card>

    </div>
  );
}
