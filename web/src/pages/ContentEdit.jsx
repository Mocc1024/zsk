import { useEffect, useState } from 'react';
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
  Typography,
  message,
} from 'antd';
import { ArrowLeftOutlined, SaveOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { categoryApi, contentApi } from '../api';
import { useAuth } from '../store/AuthContext';
import { CONTENT_STATUS } from '../utils/constants';

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
  const [loading, setLoading] = useState(Boolean(id));
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    categoryApi.options().then((res) => setCategories(res.data || []));
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

  const handleSubmit = async () => {
    const values = await form.validateFields();
    const payload = {
      ...values,
      publish_time: values.publish_time
        ? values.publish_time.format('YYYY-MM-DD HH:mm:ss')
        : null,
    };
    setSubmitting(true);
    try {
      if (id) {
        await contentApi.update(id, payload);
        message.success('保存成功');
      } else {
        await contentApi.create(payload);
        message.success('新增成功');
      }
      navigate('/content');
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
        <Typography.Title level={4} style={{ margin: 0 }}>
          {id ? '编辑内容' : '新建内容'}
        </Typography.Title>
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
          }}
          style={{ maxWidth: 900 }}
        >
          <Form.Item name="title" label="内容标题" rules={[{ required: true, message: '请输入内容标题' }]}>
            <Input placeholder="请输入标题" maxLength={200} showCount />
          </Form.Item>

          <Space size={24} wrap style={{ display: 'flex' }}>
            <Form.Item name="category_id" label="所属分类" rules={[{ required: true, message: '请选择分类' }]}>
              <Select
                placeholder="请选择分类"
                style={{ width: 240 }}
                options={categories.map((item) => ({ label: item.name, value: item.id }))}
              />
            </Form.Item>
            <Form.Item name="author" label="作者">
              <Input placeholder="请输入作者" style={{ width: 200 }} />
            </Form.Item>
            <Form.Item name="tags" label="标签">
              <Input placeholder="多个标签用逗号分隔" style={{ width: 260 }} />
            </Form.Item>
          </Space>

          <Form.Item name="cover" label="封面图地址">
            <Input placeholder="例如：https://example.com/cover.png" />
          </Form.Item>

          <Form.Item name="summary" label="内容摘要">
            <Input.TextArea rows={3} maxLength={500} showCount placeholder="一句话描述这篇内容" />
          </Form.Item>

          <Form.Item name="content" label="正文内容">
            <Input.TextArea rows={12} placeholder="支持 HTML 片段，例如 <p>段落</p>" />
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
