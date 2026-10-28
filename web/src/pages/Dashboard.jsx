import { useEffect, useMemo, useState } from 'react';
import { Card, Col, Empty, Row, Spin, Table, Tag, Typography } from 'antd';
import {
  EditOutlined,
  EyeOutlined,
  FileTextOutlined,
  FolderOutlined,
  RiseOutlined,
  TeamOutlined,
} from '@ant-design/icons';
import EChart from '../components/EChart';
import { dashboardApi } from '../api';
import { contentStatusColor, contentStatusLabel } from '../utils/constants';

const cardIcons = {
  content: <FileTextOutlined />,
  published: <RiseOutlined />,
  draft: <EditOutlined />,
  views: <EyeOutlined />,
  today: <EditOutlined />,
  month: <EditOutlined />,
  category: <FolderOutlined />,
  admin: <TeamOutlined />,
};

const cardColors = {
  content: '#2f54eb',
  published: '#52c41a',
  draft: '#faad14',
  views: '#13c2c2',
  today: '#722ed1',
  month: '#eb2f96',
  category: '#fa8c16',
  admin: '#2db7f5',
};

export default function Dashboard() {
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
      grid: { left: 40, right: 20, top: 30, bottom: 30 },
      xAxis: { type: 'category', data: trend.map((item) => item.label), boundaryGap: false },
      yAxis: { type: 'value', minInterval: 1 },
      series: [
        {
          name: '新增内容',
          type: 'line',
          smooth: true,
          data: trend.map((item) => item.total),
          areaStyle: { opacity: 0.18 },
          itemStyle: { color: '#2f54eb' },
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

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', paddingTop: 120 }}>
        <Spin size="large" tip="正在加载看板数据" />
      </div>
    );
  }

  const cards = data?.cards || [];

  return (
    <div>
      <Typography.Title level={4} style={{ marginTop: 0, marginBottom: 16 }}>
        数据看板
      </Typography.Title>

      <Row gutter={[16, 16]}>
        {cards.map((item) => (
          <Col key={item.key} xs={24} sm={12} md={8} xl={6}>
            <Card className="stat-card" bordered={false}>
              <div className="stat-title" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <span style={{ color: cardColors[item.key] || '#2f54eb' }}>{cardIcons[item.key]}</span>
                {item.title}
              </div>
              <div className="stat-value" style={{ color: cardColors[item.key] || '#2f54eb' }}>
                {item.value}
                <span style={{ fontSize: 13, color: '#8c8c8c', marginLeft: 4 }}>{item.suffix}</span>
              </div>
              <div className="stat-desc">{item.desc}</div>
            </Card>
          </Col>
        ))}
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={16}>
          <Card title="近 14 天内容新增趋势" bordered={false}>
            {data?.trend?.length ? <EChart option={trendOption} height={300} /> : <Empty />}
          </Card>
        </Col>
        <Col xs={24} lg={8}>
          <Card title="内容状态分布" bordered={false}>
            {data?.statusDistribution?.length ? <EChart option={statusOption} height={300} /> : <Empty />}
          </Card>
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} lg={10}>
          <Card title="分类内容分布" bordered={false}>
            {data?.categoryDistribution?.length ? <EChart option={categoryOption} height={300} /> : <Empty />}
          </Card>
        </Col>
        <Col xs={24} lg={14}>
          <Card title="热门内容 TOP 8" bordered={false}>
            <Table
              size="small"
              rowKey="id"
              pagination={false}
              dataSource={data?.hotContents || []}
              columns={[
                { title: '标题', dataIndex: 'title', ellipsis: true },
                { title: '分类', dataIndex: 'category_name', width: 110, render: (v) => v || '未分类' },
                {
                  title: '状态',
                  dataIndex: 'status',
                  width: 90,
                  render: (v) => <Tag color={contentStatusColor(v)}>{contentStatusLabel(v)}</Tag>,
                },
                { title: '浏览量', dataIndex: 'views', width: 90, render: (v) => v?.toLocaleString() },
              ]}
            />
          </Card>
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
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
                { title: '创建时间', dataIndex: 'create_time', width: 160 },
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
                { title: '操作人', dataIndex: 'admin_name', width: 100 },
                { title: '操作', dataIndex: 'description', ellipsis: true },
                { title: '时间', dataIndex: 'create_time', width: 160 },
              ]}
            />
          </Card>
        </Col>
      </Row>
    </div>
  );
}
