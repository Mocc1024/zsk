import { useCallback, useEffect, useState } from 'react';
import { Card, Col, Row, Statistic, Table, Typography } from 'antd';
import { fitnessStatApi } from '../../api';

const money = (v) => `¥ ${Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** 全民健身 - 统计管理：按门店展示业绩与课时、人数 */
export default function FitnessStat() {
  const [list, setList] = useState([]);
  const [loading, setLoading] = useState(false);

  const fetchList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fitnessStatApi.list();
      setList(res.data.list || []);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchList(); }, [fetchList]);

  const totals = list.reduce(
    (acc, r) => {
      const p = r.performance || {};
      acc.total += Number(p.total || 0);
      acc.year += Number(p.year || 0);
      acc.month += Number(p.month || 0);
      acc.today += Number(p.today || 0);
      acc.done += Number(r.lesson_done || 0);
      acc.pending += Number(r.lesson_pending || 0);
      acc.member += Number(r.member_count || 0);
      acc.coach += Number(r.coach_count || 0);
      acc.enterprise += Number(r.enterprise_count || 0);
      return acc;
    },
    { total: 0, year: 0, month: 0, today: 0, done: 0, pending: 0, member: 0, coach: 0, enterprise: 0 }
  );

  const columns = [
    { title: '门店', dataIndex: 'store_name', width: 140, fixed: 'left' },
    {
      title: '总业绩', dataIndex: ['performance', 'total'], width: 130, align: 'right',
      render: (v) => money(v), sorter: (a, b) => a.performance.total - b.performance.total,
    },
    {
      title: '今年业绩', dataIndex: ['performance', 'year'], width: 130, align: 'right',
      render: (v) => money(v),
    },
    {
      title: '本月业绩', dataIndex: ['performance', 'month'], width: 130, align: 'right',
      render: (v) => money(v),
    },
    {
      title: '今日业绩', dataIndex: ['performance', 'today'], width: 130, align: 'right',
      render: (v) => money(v),
    },
    { title: '已上课时', dataIndex: 'lesson_done', width: 110, align: 'right' },
    { title: '待上课时', dataIndex: 'lesson_pending', width: 110, align: 'right' },
    { title: '会员数', dataIndex: 'member_count', width: 100, align: 'right' },
    { title: '教练数', dataIndex: 'coach_count', width: 100, align: 'right' },
    { title: '企业数', dataIndex: 'enterprise_count', width: 100, align: 'right' },
  ];

  return (
    <div>
      <Typography.Title level={4} style={{ marginBottom: 16 }}>统计管理</Typography.Title>

      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="总业绩" value={totals.total} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="今年业绩" value={totals.year} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="本月业绩" value={totals.month} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="今日业绩" value={totals.today} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="已上课时" value={totals.done} suffix="次" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="待上课时" value={totals.pending} suffix="次" /></Card></Col>
        <Col xs={24} sm={8} md={4}><Card bordered={false}><Statistic title="会员数" value={totals.member} /></Card></Col>
        <Col xs={24} sm={8} md={4}><Card bordered={false}><Statistic title="教练数（在职）" value={totals.coach} /></Card></Col>
        <Col xs={24} sm={8} md={4}><Card bordered={false}><Statistic title="企业数" value={totals.enterprise} /></Card></Col>
      </Row>

      <Card bordered={false}>
        <Table
          rowKey="store_id"
          loading={loading}
          dataSource={list}
          columns={columns}
          scroll={{ x: 1160 }}
          pagination={false}
          summary={() => (
            <Table.Summary fixed>
              <Table.Summary.Row>
                <Table.Summary.Cell index={0}><strong>合计</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={1} align="right"><strong>{money(totals.total)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right">{money(totals.year)}</Table.Summary.Cell>
                <Table.Summary.Cell index={3} align="right">{money(totals.month)}</Table.Summary.Cell>
                <Table.Summary.Cell index={4} align="right">{money(totals.today)}</Table.Summary.Cell>
                <Table.Summary.Cell index={5} align="right">{totals.done}</Table.Summary.Cell>
                <Table.Summary.Cell index={6} align="right">{totals.pending}</Table.Summary.Cell>
                <Table.Summary.Cell index={7} align="right">{totals.member}</Table.Summary.Cell>
                <Table.Summary.Cell index={8} align="right">{totals.coach}</Table.Summary.Cell>
                <Table.Summary.Cell index={9} align="right">{totals.enterprise}</Table.Summary.Cell>
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </Card>
    </div>
  );
}
