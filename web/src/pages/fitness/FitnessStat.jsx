import { useCallback, useEffect, useState } from 'react';
import { Button, Card, Col, Row, Statistic, Table, Typography, message } from 'antd';
import { ExportOutlined } from '@ant-design/icons';
import { fitnessStatApi, fitnessStoreApi } from '../../api';
import { useAuth } from '../../store/AuthContext';
import { downloadExport } from '../../utils/export';

const money = (v) => `¥ ${Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** 全民健身 - 统计管理：按门店展示业绩与课时、人数 */
export default function FitnessStat() {
  const { hasPermission } = useAuth();
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

  const handleExportStores = async () => {
    const res = await fitnessStoreApi.exportList();
    downloadExport(res, '门店列表.csv');
    message.success(`已导出 ${res.data?.count ?? 0} 条记录`);
  };

  const totals = list.reduce(
    (acc, r) => {
      const p = r.performance || {};
      const card = p.card || {};
      const coach = p.coach || {};
      acc.cardTotal += Number(card.total || 0);
      acc.cardYear += Number(card.year || 0);
      acc.cardMonth += Number(card.month || 0);
      acc.cardToday += Number(card.today || 0);
      acc.coachTotal += Number(coach.total || 0);
      acc.coachYear += Number(coach.year || 0);
      acc.coachMonth += Number(coach.month || 0);
      acc.coachToday += Number(coach.today || 0);
      acc.done += Number(r.lesson_done || 0);
      acc.pending += Number(r.lesson_pending || 0);
      acc.member += Number(r.member_count || 0);
      acc.coachCount += Number(r.coach_count || 0);
      acc.enterprise += Number(r.enterprise_count || 0);
      return acc;
    },
    {
      cardTotal: 0, cardYear: 0, cardMonth: 0, cardToday: 0,
      coachTotal: 0, coachYear: 0, coachMonth: 0, coachToday: 0,
      done: 0, pending: 0, member: 0, coachCount: 0, enterprise: 0,
    }
  );

  const perfColumns = (type) => [
    {
      title: '总业绩', dataIndex: ['performance', type, 'total'], width: 120, align: 'right',
      render: (v) => money(v),
      sorter: (a, b) => Number(a.performance?.[type]?.total || 0) - Number(b.performance?.[type]?.total || 0),
    },
    { title: '今年业绩', dataIndex: ['performance', type, 'year'], width: 120, align: 'right', render: (v) => money(v) },
    { title: '本月业绩', dataIndex: ['performance', type, 'month'], width: 120, align: 'right', render: (v) => money(v) },
    { title: '今日业绩', dataIndex: ['performance', type, 'today'], width: 120, align: 'right', render: (v) => money(v) },
  ];

  const columns = [
    { title: '门店', dataIndex: 'store_name', width: 140, fixed: 'left' },
    { title: '会员卡业绩', children: perfColumns('card') },
    { title: '教练绩效业绩', children: perfColumns('coach') },
    { title: '已上课时', dataIndex: 'lesson_done', width: 110, align: 'right' },
    { title: '待上课时', dataIndex: 'lesson_pending', width: 110, align: 'right' },
    { title: '会员数', dataIndex: 'member_count', width: 100, align: 'right' },
    { title: '教练数', dataIndex: 'coach_count', width: 100, align: 'right' },
    { title: '企业数', dataIndex: 'enterprise_count', width: 100, align: 'right' },
  ];

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>统计管理</Typography.Title>
        {hasPermission('fitness.store.list') && (
          <Button icon={<ExportOutlined />} onClick={handleExportStores}>导出Excel</Button>
        )}
      </div>

      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="会员卡总业绩" value={totals.cardTotal} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="会员卡今年业绩" value={totals.cardYear} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="会员卡本月业绩" value={totals.cardMonth} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="会员卡今日业绩" value={totals.cardToday} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="教练绩效总业绩" value={totals.coachTotal} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="教练绩效今年业绩" value={totals.coachYear} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="教练绩效本月业绩" value={totals.coachMonth} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="教练绩效今日业绩" value={totals.coachToday} precision={2} prefix="¥" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="已上课时" value={totals.done} suffix="次" /></Card></Col>
        <Col xs={24} sm={12} md={6}><Card bordered={false}><Statistic title="待上课时" value={totals.pending} suffix="次" /></Card></Col>
        <Col xs={24} sm={8} md={4}><Card bordered={false}><Statistic title="会员数" value={totals.member} /></Card></Col>
        <Col xs={24} sm={8} md={4}><Card bordered={false}><Statistic title="教练数（在职）" value={totals.coachCount} /></Card></Col>
        <Col xs={24} sm={8} md={4}><Card bordered={false}><Statistic title="企业数" value={totals.enterprise} /></Card></Col>
      </Row>

      <Card bordered={false}>
        <Table
          rowKey="store_id"
          loading={loading}
          dataSource={list}
          columns={columns}
          scroll={{ x: 1900 }}
          pagination={false}
          summary={() => (
            <Table.Summary fixed>
              <Table.Summary.Row>
                <Table.Summary.Cell index={0}><strong>合计</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={1} align="right"><strong>{money(totals.cardTotal)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right">{money(totals.cardYear)}</Table.Summary.Cell>
                <Table.Summary.Cell index={3} align="right">{money(totals.cardMonth)}</Table.Summary.Cell>
                <Table.Summary.Cell index={4} align="right">{money(totals.cardToday)}</Table.Summary.Cell>
                <Table.Summary.Cell index={5} align="right"><strong>{money(totals.coachTotal)}</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={6} align="right">{money(totals.coachYear)}</Table.Summary.Cell>
                <Table.Summary.Cell index={7} align="right">{money(totals.coachMonth)}</Table.Summary.Cell>
                <Table.Summary.Cell index={8} align="right">{money(totals.coachToday)}</Table.Summary.Cell>
                <Table.Summary.Cell index={9} align="right">{totals.done}</Table.Summary.Cell>
                <Table.Summary.Cell index={10} align="right">{totals.pending}</Table.Summary.Cell>
                <Table.Summary.Cell index={11} align="right">{totals.member}</Table.Summary.Cell>
                <Table.Summary.Cell index={12} align="right">{totals.coachCount}</Table.Summary.Cell>
                <Table.Summary.Cell index={13} align="right">{totals.enterprise}</Table.Summary.Cell>
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </Card>
    </div>
  );
}
