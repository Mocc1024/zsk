import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, Col, Empty, Row, Space, Spin, Table, Tag, Tooltip, Typography } from 'antd';
import {
  EyeOutlined,
  FileTextOutlined,
  FlagOutlined,
  LikeOutlined,
  MessageOutlined,
  RiseOutlined,
  StarOutlined,
  TeamOutlined,
  UserAddOutlined,
} from '@ant-design/icons';
import EChart from '../components/EChart';
import { dashboardApi } from '../api';
import { useAuth } from '../store/AuthContext';
import { contentStatusColor, contentStatusLabel } from '../utils/constants';

const cardIcons = {
  content: <FileTextOutlined />,
  published: <RiseOutlined />,
  views: <EyeOutlined />,
  users: <TeamOutlined />,
  todayUser: <UserAddOutlined />,
  comments: <MessageOutlined />,
  favorites: <StarOutlined />,
  likes: <LikeOutlined />,
};

const cardColors = {
  content: '#2f54eb',
  published: '#52c41a',
  views: '#13c2c2',
  users: '#722ed1',
  todayUser: '#eb2f96',
  comments: '#fa8c16',
  favorites: '#faad14',
  likes: '#f5222d',
};

const todoIcons = {
  comment_pending: <MessageOutlined />,
  report_pending: <FlagOutlined />,
  draft: <FileTextOutlined />,
};

/**
 * 待办条目需要的权限：没有对应权限就不给跳转，
 * 免得点进去被后端 403 弹回来
 */
const todoPermission = {
  comment_pending: 'comment.audit',
  report_pending: 'report.list',
  draft: null,
};

export default function Dashboard() {
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    dashboardApi
      .index()
      .then((res) => setData(res.data))
      .finally(() => setLoading(false));
  }, []);

  const trendOption = useMemo(() => {
    const trend = data?.trend || [];
    return {
      tooltip: { trigger: 'axis' },
      legend: { data: ['内容新增', '用户新增'], top: 0 },
      grid: { left: 40, right: 20, top: 40, bottom: 30 },
      xAxis: { type: 'category', data: trend.map((item) => item.label), boundaryGap: false },
      yAxis: { type: 'value', minInterval: 1 },
      series: [
        {
          name: '内容新增',
          type: 'line',
          smooth: true,
          data: trend.map((item) => item.content),
          areaStyle: { opacity: 0.18 },
          itemStyle: { color: '#2f54eb' },
        },
        {
          name: '用户新增',
          type: 'line',
          smooth: true,
          data: trend.map((item) => item.user),
          areaStyle: { opacity: 0.15 },
          itemStyle: { color: '#52c41a' },
        },
      ],
    };
  }, [data]);

  const statusOption = useMemo(() => {
    const list = data?.statusDistribution || [];
    return {
      tooltip: { trigger: 'item' },
      legend: { bottom: 0 },
      series: [
        {
          type: 'pie',
          radius: ['45%', '70%'],
          center: ['50%', '45%'],
          data: list.map((item) => ({ name: item.name, value: item.value })),
          label: { formatter: '{b}: {c}' },
        },
      ],
    };
  }, [data]);

  const categoryOption = useMemo(() => {
    const list = data?.categoryDistribution || [];
    return {
      tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
      grid: { left: 90, right: 30, top: 20, bottom: 30 },
      xAxis: { type: 'value', minInterval: 1 },
      yAxis: { type: 'category', data: list.map((item) => item.name || '未分类').reverse() },
      series: [
        {
          name: '内容数',
          type: 'bar',
          barWidth: 16,
          data: list.map((item) => item.value).reverse(),
          itemStyle: { borderRadius: [0, 6, 6, 0], color: '#5b8ff9' },
        },
      ],
    };
  }, [data]);

  const levelOption = useMemo(() => {
    // 0 人的等级不进图：饼图会给每个 0 值扇区画标签，全叠在圆心没法看。
    // 等级是随用户成长逐渐出现的，有人的等级自然会出现在图上。
    const list = (data?.levelDistribution || []).filter((item) => item.value > 0);
    return {
      tooltip: { trigger: 'item' },
      legend: { bottom: 0, type: 'scroll' },
      series: [
        {
          type: 'pie',
          radius: ['40%', '68%'],
          center: ['50%', '44%'],
          data: list.map((item) => ({ name: item.name, value: item.value })),
          label: { formatter: '{c}' },
        },
      ],
    };
  }, [data]);

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 120 }}>
        <Spin size="large" tip="正在加载看板数据" />
      </div>
    );
  }

  const todo = data?.todo || [];
  const groups = data?.groups || [];

  return (
    <div>
      <Typography.Title level={4} style={{ marginTop: 0, marginBottom: 4 }}>
        数据看板
      </Typography.Title>
      <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 16 }}>
        先看有什么要处理，再看站点整体状况
      </Typography.Text>

      {/* ========== 待处理 ========== */}
      <Row gutter={[16, 16]}>
        {todo.map((item) => {
          const perm = todoPermission[item.key];
          const canGo = perm ? hasPermission(perm) : true;
          const pending = Number(item.value) > 0;

          return (
            <Col key={item.key} xs={24} sm={8}>
              <Card
                bordered={false}
                hoverable={canGo}
                onClick={canGo ? () => navigate(item.link) : undefined}
                style={{
                  cursor: canGo ? 'pointer' : 'default',
                  borderLeft: `3px solid ${pending ? '#faad14' : '#d9d9d9'}`,
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: pending ? '#d46b08' : '#8c8c8c' }}>
                  {todoIcons[item.key]}
                  <span>{item.title}</span>
                  {pending ? <Tag color="orange">待处理</Tag> : null}
                </div>
                <div
                  className="stat-value"
                  style={{ color: pending ? '#d46b08' : '#595959', margin: '6px 0' }}
                >
                  {item.value}
                  <span style={{ fontSize: 13, color: '#8c8c8c', marginLeft: 4 }}>项</span>
                </div>
                <div style={{ fontSize: 12, color: '#8c8c8c' }}>
                  {canGo ? item.desc : '无处理权限'}
                </div>
              </Card>
            </Col>
          );
        })}
      </Row>

      {/* ========== 核心指标（内容 / 用户 / 互动）========== */}
      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        {groups.map((group) => (
          <Col key={group.key} xs={24} lg={8}>
            <Card title={group.title} bordered={false} styles={{ body: { paddingTop: 12 } }}>
              <Row gutter={[12, 12]}>
                {group.items.map((item) => (
                  <Col key={item.key} span={24 / group.items.length}>
                    <div>
                      <div
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: 5,
                          fontSize: 13,
                          color: '#8c8c8c',
                        }}
                      >
                        <span style={{ color: cardColors[item.key] || '#2f54eb' }}>
                          {cardIcons[item.key]}
                        </span>
                        {item.title}
                      </div>
                      <div
                        className="stat-value"
                        style={{
                          color: cardColors[item.key] || '#2f54eb',
                          fontSize: 26,
                          margin: '2px 0',
                        }}
                      >
                        {Number(item.value).toLocaleString()}
                        <span style={{ fontSize: 12, color: '#8c8c8c', marginLeft: 3 }}>
                          {item.suffix}
                        </span>
                      </div>
                      <div style={{ fontSize: 12, color: '#bfbfbf' }}>{item.desc}</div>
                    </div>
                  </Col>
                ))}
              </Row>
            </Card>
          </Col>
        ))}
      </Row>

      {/* ========== 趋势 + 状态分布 ========== */}
      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={16}>
          <Card title="近 14 天新增趋势" bordered={false}>
            {data?.trend?.length ? <EChart option={trendOption} height={300} /> : <Empty />}
          </Card>
        </Col>
        <Col xs={24} lg={8}>
          <Card title="内容状态分布" bordered={false}>
            {data?.statusDistribution?.length ? <EChart option={statusOption} height={300} /> : <Empty />}
          </Card>
        </Col>
      </Row>

      {/* ========== 分类分布 + 用户等级分布 ========== */}
      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={12}>
          <Card title="分类内容分布" bordered={false}>
            {data?.categoryDistribution?.length ? <EChart option={categoryOption} height={280} /> : <Empty />}
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card
            title="用户等级分布"
            bordered={false}
            extra={
              <Tooltip title="等级只是成长标记，不影响功能权限">
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  仅作标记
                </Typography.Text>
              </Tooltip>
            }
          >
            {data?.levelDistribution?.length ? <EChart option={levelOption} height={280} /> : <Empty />}
          </Card>
        </Col>
      </Row>

      {/* ========== 明细 ========== */}
      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={12}>
          <Card title="热门内容 TOP 8" bordered={false}>
            <Table
              size="small"
              rowKey="id"
              pagination={false}
              dataSource={data?.hotContents || []}
              columns={[
                { title: '标题', dataIndex: 'title', ellipsis: true },
                { title: '分类', dataIndex: 'category_name', width: 100, render: (v) => v || '未分类' },
                {
                  title: '状态',
                  dataIndex: 'status',
                  width: 84,
                  render: (v) => <Tag color={contentStatusColor(v)}>{contentStatusLabel(v)}</Tag>,
                },
                { title: '浏览量', dataIndex: 'views', width: 84, render: (v) => v?.toLocaleString() },
              ]}
            />
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card title="最新内容" bordered={false}>
            <Table
              size="small"
              rowKey="id"
              pagination={false}
              dataSource={data?.latestContents || []}
              columns={[
                { title: '标题', dataIndex: 'title', ellipsis: true },
                { title: '作者', dataIndex: 'author', width: 90 },
                { title: '创建时间', dataIndex: 'create_time', width: 150 },
              ]}
            />
          </Card>
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={12}>
          <Card title="最新注册用户" bordered={false}>
            <Table
              size="small"
              rowKey="id"
              pagination={false}
              locale={{ emptyText: <Empty description="还没有用户注册" /> }}
              dataSource={data?.latestUsers || []}
              columns={[
                {
                  title: '用户',
                  dataIndex: 'nickname',
                  ellipsis: true,
                  render: (v, record) => v || record.username,
                },
                {
                  title: '等级',
                  dataIndex: 'level',
                  width: 80,
                  render: (v) => <Tag color="blue">Lv{v ?? 1}</Tag>,
                },
                { title: '注册时间', dataIndex: 'create_time', width: 150 },
              ]}
            />
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card title="最近操作日志" bordered={false}>
            <Table
              size="small"
              rowKey="id"
              pagination={false}
              dataSource={data?.latestLogs || []}
              columns={[
                { title: '操作人', dataIndex: 'admin_name', width: 90 },
                { title: '操作', dataIndex: 'description', ellipsis: true },
                { title: '时间', dataIndex: 'create_time', width: 150 },
              ]}
            />
          </Card>
        </Col>
      </Row>

      <div style={{ marginTop: 12, color: '#bfbfbf', fontSize: 12 }}>
        <Space size={16}>
          <span>分类 {data?.meta?.category ?? 0} 个</span>
          <span>管理员 {data?.meta?.admin ?? 0} 人</span>
          <span>已下架 {data?.meta?.offline ?? 0} 条</span>
        </Space>
      </div>
    </div>
  );
}
