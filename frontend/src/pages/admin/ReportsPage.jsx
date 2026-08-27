import { useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import api from '../../api/axios';
import AdminShell from '../../components/AdminShell';
import { formatBDT } from '../../utils/formatPrice';
import './admin.css';

const COLORS = ['#2563eb', '#93c5fd', '#15803d', '#b45309', '#dc2626', '#9333ea', '#0891b2', '#ea580c', '#65a30d', '#db2777', '#4f46e5'];

export default function ReportsPage() {
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
        <p className="muted">Loading reports...</p>
      </AdminShell>
    );
  }

  return (
    <AdminShell title="Reports">
      <div className="kpi-grid">
        <Kpi label="Total revenue (paid)" value={formatBDT(revenue?.totals?.gross ?? 0)} />
        <Kpi label="Platform commission" value={formatBDT(revenue?.totals?.commission ?? 0)} />
        <Kpi label="Payable to owners" value={formatBDT(revenue?.totals?.owner_payable ?? 0)} />
      </div>

      <div className="two-col-grid">
        <div className="card">
          <div className="card-body">
            <h2 className="section-title">Occupancy this month</h2>
            <div style={{ height: 288 }}>
              {occupancyThisMonth.length === 0 ? (
                <div className="empty-state">No confirmed bookings this month.</div>
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

        <div className="card">
          <div className="card-body">
            <h2 className="section-title">Revenue by billboard</h2>
            <div style={{ height: 288 }}>
              {revenueByBoard.length === 0 ? (
                <div className="empty-state">No paid bookings yet.</div>
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

        <div className="card" style={{ gridColumn: 'span 2' }}>
          <div className="card-body">
            <h2 className="section-title">Inventory by type</h2>
            <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
              <div style={{ height: 288, flex: 1, minWidth: 300 }}>
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
              <ul style={{ listStyle: 'none', padding: 0, margin: 0, minWidth: 180, display: 'flex', flexDirection: 'column', gap: 10 }}>
                {typeShare.map((t, i) => (
                  <li key={t.name} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 14 }}>
                    <span style={{ width: 12, height: 12, borderRadius: '50%', background: COLORS[i % COLORS.length], flexShrink: 0 }} />
                    <span style={{ textTransform: 'capitalize' }}>{t.name}</span>
                    <span style={{ marginLeft: 'auto', fontWeight: 500, color: '#64748b' }}>{t.value}</span>
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

function Kpi({ label, value }) {
  return (
    <div className="kpi-card">
      <div className="kpi-label">{label}</div>
      <div className="kpi-value" style={{ marginTop: 4 }}>{value}</div>
    </div>
  );
}