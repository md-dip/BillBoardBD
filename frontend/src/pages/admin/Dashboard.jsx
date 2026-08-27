import { useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CalendarCheck, DollarSign, Megaphone, ShieldAlert, TrendingUp } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './admin.css';

function daysUntil(dateStr) {
  return Math.round((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

export default function AdminDashboard() {
  const [billboards, setBillboards] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [revenue, setRevenue] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    function fetchAll() {
      Promise.all([
        api.get('/admin/billboards'),
        api.get('/admin/bookings'),
        api.get('/admin/reports/revenue'),
      ])
        .then(([bbRes, bkRes, revRes]) => {
          setBillboards(bbRes.data.data.data);
          setBookings(bkRes.data.data);
          setRevenue(revRes.data.data);
        })
        .catch((err) => console.error('Dashboard load failed', err))
        .finally(() => setLoading(false));
    }

    fetchAll();
    const interval = setInterval(fetchAll, 30000); // refresh every 30 seconds
    return () => clearInterval(interval);
  }, []);

  const stats = useMemo(() => ({
    revenue: revenue?.totals?.gross ?? 0,
    commission: revenue?.totals?.commission ?? 0,
    pending: bookings.filter((b) => b.status === 'pending_admin_review').length,
    expiring: billboards.filter((b) => b.permit_expiry_date && daysUntil(b.permit_expiry_date) < 90).length,
  }), [bookings, billboards, revenue]);

  const chartData = useMemo(() => {
    if (!revenue?.rows) return [];
    const map = new Map();
    for (const r of revenue.rows) {
      const entry = map.get(r.month) ?? { month: r.month, revenue: 0, commission: 0 };
      entry.revenue += Number(r.gross);
      entry.commission += Number(r.commission);
      map.set(r.month, entry);
    }
    return [...map.values()].sort((a, b) => a.month.localeCompare(b.month));
  }, [revenue]);

  if (loading) {
    return (
      <AdminShell title="Dashboard">
        <p className="muted">Loading...</p>
      </AdminShell>
    );
  }

  return (
    <AdminShell title="Dashboard">
      <div className="kpi-grid">
        <StatCard icon={DollarSign} label="Total revenue" value={formatBDT(stats.revenue)} />
        <StatCard icon={TrendingUp} label="Platform commission" value={formatBDT(stats.commission)} />
        <StatCard icon={CalendarCheck} label="Pending bookings" value={String(stats.pending)} accent="warning" />
        <StatCard icon={ShieldAlert} label="Permits expiring <90d" value={String(stats.expiring)} accent="destructive" />
      </div>

      <div className="card mt-6">
        <div className="card-body">
          <div className="flex items-center flex-gap-2 mb-4">
            <TrendingUp size={16} color="#2563eb" />
            <h2 className="section-title" style={{ margin: 0 }}>Revenue by month</h2>
          </div>
          <div style={{ height: 288 }}>
            {chartData.length === 0 ? (
              <div className="empty-state">No paid bookings yet.</div>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" opacity={0.2} />
                  <XAxis dataKey="month" />
                  <YAxis tickFormatter={(v) => `৳${(v / 1000).toFixed(0)}k`} />
                  <Tooltip formatter={(v) => formatBDT(v)} />
                  <Bar dataKey="revenue" fill="#2563eb" radius={[6, 6, 0, 0]} />
                  <Bar dataKey="commission" fill="#93c5fd" radius={[6, 6, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </div>
      </div>

      <div className="two-col-grid mt-6">
        <div className="card">
          <div className="card-body">
            <div className="flex items-center flex-gap-2 mb-4">
              <Megaphone size={16} color="#2563eb" />
              <h2 className="section-title" style={{ margin: 0 }}>Inventory</h2>
            </div>
            <div className="mini-stat-grid">
              <MiniStat label="Total billboards" value={billboards.length} />
              <MiniStat label="Available" value={billboards.filter((b) => b.status === 'available').length} />
              <MiniStat label="Booked" value={billboards.filter((b) => b.status === 'booked').length} />
              <MiniStat label="Hidden" value={billboards.filter((b) => b.status === 'hidden').length} />
            </div>
          </div>
        </div>
        <div className="card">
          <div className="card-body">
            <div className="flex items-center flex-gap-2 mb-4">
              <CalendarCheck size={16} color="#2563eb" />
              <h2 className="section-title" style={{ margin: 0 }}>Booking pipeline</h2>
            </div>
            <div className="mini-stat-grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
              <MiniStat label="Pending review" value={bookings.filter((b) => b.status === 'pending_admin_review').length} />
              <MiniStat label="Awaiting owner" value={bookings.filter((b) => b.status === 'pending_owner_approval').length} />
              <MiniStat label="Confirmed" value={bookings.filter((b) => b.status === 'confirmed').length} />
              <MiniStat label="Paid in full" value={bookings.filter((b) => b.status === 'paid_in_full').length} />
              <MiniStat label="Proof review" value={bookings.filter((b) => b.status === 'pending_proof_review').length} />
              <MiniStat label="Active" value={bookings.filter((b) => b.status === 'active').length} />
              <MiniStat label="Rejected" value={bookings.filter((b) => b.status === 'rejected').length} />
            </div>
          </div>
        </div>
      </div>
    </AdminShell>
  );
}

function StatCard({ icon: Icon, label, value, accent }) {
  return (
    <div className="kpi-card">
      <div className="kpi-header">
        <span className="kpi-label">{label}</span>
        <span className={`kpi-icon ${accent || ''}`}>
          <Icon size={16} />
        </span>
      </div>
      <div className="kpi-value">{value}</div>
    </div>
  );
}

function MiniStat({ label, value }) {
  return (
    <div className="mini-stat">
      <div className="mini-stat-label">{label}</div>
      <div className="mini-stat-value">{value}</div>
    </div>
  );
}