import { useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Reports.css';

const COLORS = ['#2563eb', '#93c5fd', '#15803d', '#b45309', '#dc2626', '#9333ea', '#0891b2', '#ea580c', '#65a30d', '#db2777', '#4f46e5'];

const KPIS = ['total-revenue', 'platform-commission', 'payable-to-owners'];

export default function AdminReports() {
    usePageTitle('Admin Reports');

  const [revenue, setRevenue] = useState(null);
  const [occupancy, setOccupancy] = useState([]);
  const [billboards, setBillboards] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get('/admin/reports/revenue'),
      api.get('/admin/reports/occupancy'),
      api.get('/admin/billboards'),
    ])
      .then(([revenueRes, occupancyRes, billboardsRes]) => {
        setRevenue(revenueRes.data.data);
        setOccupancy(occupancyRes.data.data);
        setBillboards(billboardsRes.data.data.data);
      })
      .catch((err) => console.error('Reports load failed', err))
      .finally(() => setLoading(false));
  }, []);

  const occupancyThisMonth = useMemo(() => {
    const thisMonth = new Date().toISOString().slice(0, 7);
    return occupancy
      .filter((r) => r.month === thisMonth)
      .map((r) => ({ name: (r.billboard_title || '').slice(0, 22), occupancy: r.occupancy_rate }));
  }, [occupancy]);

  const revenueByBoard = useMemo(() => {
    if (!revenue?.rows) return [];
    const map = new Map();
    for (const r of revenue.rows) {
      map.set(r.billboard_title, (map.get(r.billboard_title) ?? 0) + Number(r.gross));
    }
    return [...map.entries()].map(([name, value]) => ({ name: (name || '').slice(0, 22), revenue: value }));
  }, [revenue]);

  const typeShare = useMemo(() => {
    const m = new Map();
    for (const b of billboards) m.set(b.type, (m.get(b.type) ?? 0) + 1);
    return [...m.entries()].map(([name, value]) => ({ name, value }));
  }, [billboards]);

  if (loading) {
    return (
      <AdminShell title="Reports">
        <p className="admin-reports-muted">Loading reports...</p>
      </AdminShell>
    );
  }

  const kpiValues = {
    'total-revenue': { label: 'Total revenue (paid)', value: formatBDT(revenue?.totals?.gross ?? 0) },
    'platform-commission': { label: 'Platform commission', value: formatBDT(revenue?.totals?.commission ?? 0) },
    'payable-to-owners': { label: 'Payable to owners', value: formatBDT(revenue?.totals?.owner_payable ?? 0) },
  };

  return (
    <AdminShell title="Reports">
      <div className="admin-reports-kpi-grid">
        {KPIS.map((slug) => (
          <div className={`admin-reports-kpi-card-${slug}`} key={slug}>
            <div className={`admin-reports-kpi-label-${slug}`}>{kpiValues[slug].label}</div>
            <div className={`admin-reports-kpi-value-${slug}`}>{kpiValues[slug].value}</div>
          </div>
        ))}
      </div>

      <div className="admin-reports-charts-grid">
        <div className="admin-reports-occupancy-card">
          <div className="admin-reports-occupancy-card-body">
            <h2 className="admin-reports-occupancy-title">Occupancy this month</h2>
            <div className="admin-reports-occupancy-chart-container">
              {occupancyThisMonth.length === 0 ? (
                <div className="admin-reports-occupancy-empty">No confirmed bookings this month.</div>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={occupancyThisMonth} layout="vertical" margin={{ left: 20 }}>
                    <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                    <XAxis type="number" domain={[0, 100]} tickFormatter={(v) => `${v}%`} />
                    <YAxis type="category" dataKey="name" width={130} />
                    <Tooltip formatter={(v) => `${v}%`} />
                    <Bar dataKey="occupancy" fill="#2563eb" radius={[0, 6, 6, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>
        </div>

        <div className="admin-reports-revenue-card">
          <div className="admin-reports-revenue-card-body">
            <h2 className="admin-reports-revenue-title">Revenue by billboard</h2>
            <div className="admin-reports-revenue-chart-container">
              {revenueByBoard.length === 0 ? (
                <div className="admin-reports-revenue-empty">No paid bookings yet.</div>
              ) : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={revenueByBoard} layout="vertical" margin={{ left: 20 }}>
                    <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                    <XAxis type="number" tickFormatter={(v) => `৳${(v / 1000).toFixed(0)}k`} />
                    <YAxis type="category" dataKey="name" width={130} />
                    <Tooltip formatter={(v) => formatBDT(v)} />
                    <Bar dataKey="revenue" fill="#93c5fd" radius={[0, 6, 6, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>
        </div>

        <div className="admin-reports-inventory-card">
          <div className="admin-reports-inventory-card-body">
            <h2 className="admin-reports-inventory-title">Inventory by type</h2>
            <div className="admin-reports-inventory-content">
              <div className="admin-reports-inventory-chart-container">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={typeShare} dataKey="value" nameKey="name" outerRadius={100} label>
                      {typeShare.map((_, i) => (
                        <Cell key={i} fill={COLORS[i % COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <ul className="admin-reports-inventory-legend-list">
                {typeShare.map((t, i) => (
                  <li key={t.name} className="admin-reports-inventory-legend-item">
                    <span
                      className={`admin-reports-inventory-legend-dot admin-reports-inventory-legend-dot-${i % COLORS.length}`}
                    />
                    <span className="admin-reports-inventory-legend-label">{t.name}</span>
                    <span className="admin-reports-inventory-legend-value">{t.value}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </div>
    </AdminShell>
  );
}
