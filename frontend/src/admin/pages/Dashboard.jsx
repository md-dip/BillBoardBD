import { useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CalendarCheck, DollarSign, Megaphone, ShieldAlert, TrendingUp } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Dashboard.css';

function daysUntil(dateStr) {
  return Math.round((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

const KPIS = ['revenue', 'commission', 'pending-bookings', 'permits-expiring'];
const BOXES = ['inventory', 'booking-pipeline'];

// "Inventory" box — every mini-stat gets its own fully independent class
// tree (mini-stat / label / value), so editing one stat's color in the CSS
// never touches the others or the "Booking pipeline" box next to it.
function InventoryBox({ billboards }) {
  const stats = [
    { slug: 'total-billboards', label: 'Total billboards', value: billboards.length },
    { slug: 'available', label: 'Available', value: billboards.filter((b) => b.status === 'available').length },
    { slug: 'booked', label: 'Booked', value: billboards.filter((b) => b.status === 'booked').length },
    { slug: 'hidden', label: 'Hidden', value: billboards.filter((b) => b.status === 'hidden').length },
  ];

  return (
    <div className="admin-dashboard-inventory-card">
      <div className="admin-dashboard-inventory-card-body">
        <div className="admin-dashboard-inventory-header">
          <Megaphone size={16} className="admin-dashboard-inventory-header-icon" />
          <h2 className="admin-dashboard-inventory-title">Inventory</h2>
        </div>
        <div className="admin-dashboard-inventory-mini-stat-grid">
          {stats.map((s) => (
            <div className={`admin-dashboard-inventory-mini-stat-${s.slug}`} key={s.slug}>
              <div className={`admin-dashboard-inventory-mini-stat-label-${s.slug}`}>{s.label}</div>
              <div className={`admin-dashboard-inventory-mini-stat-value-${s.slug}`}>{s.value}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// "Booking pipeline" box — same treatment: every mini-stat fully independent.
function BookingPipelineBox({ bookings }) {
  const stats = [
    { slug: 'pending-review', label: 'Pending review', value: bookings.filter((b) => b.status === 'pending_admin_review').length },
    { slug: 'awaiting-owner', label: 'Awaiting owner', value: bookings.filter((b) => b.status === 'pending_owner_approval').length },
    { slug: 'confirmed', label: 'Confirmed', value: bookings.filter((b) => b.status === 'confirmed').length },
    { slug: 'paid-in-full', label: 'Paid in full', value: bookings.filter((b) => b.status === 'paid_in_full').length },
    { slug: 'proof-review', label: 'Proof review', value: bookings.filter((b) => b.status === 'pending_proof_review').length },
    { slug: 'active', label: 'Active', value: bookings.filter((b) => b.status === 'active').length },
    { slug: 'rejected', label: 'Rejected', value: bookings.filter((b) => b.status === 'rejected').length },
  ];

  return (
    <div className="admin-dashboard-booking-pipeline-card">
      <div className="admin-dashboard-booking-pipeline-card-body">
        <div className="admin-dashboard-booking-pipeline-header">
          <CalendarCheck size={16} className="admin-dashboard-booking-pipeline-header-icon" />
          <h2 className="admin-dashboard-booking-pipeline-title">Booking pipeline</h2>
        </div>
        <div className="admin-dashboard-booking-pipeline-mini-stat-grid">
          {stats.map((s) => (
            <div className={`admin-dashboard-booking-pipeline-mini-stat-${s.slug}`} key={s.slug}>
              <div className={`admin-dashboard-booking-pipeline-mini-stat-label-${s.slug}`}>{s.label}</div>
              <div className={`admin-dashboard-booking-pipeline-mini-stat-value-${s.slug}`}>{s.value}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
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
        <p className="admin-dashboard-muted">Loading...</p>
      </AdminShell>
    );
  }

  const kpiValues = {
    revenue: { label: 'Total revenue', value: formatBDT(stats.revenue), icon: DollarSign },
    commission: { label: 'Platform commission', value: formatBDT(stats.commission), icon: TrendingUp },
    'pending-bookings': { label: 'Pending bookings', value: String(stats.pending), icon: CalendarCheck },
    'permits-expiring': { label: 'Permits expiring <90d', value: String(stats.expiring), icon: ShieldAlert },
  };

  return (
    <AdminShell title="Dashboard">
      <div className="admin-dashboard-kpi-grid">
        {KPIS.map((slug) => {
          const k = kpiValues[slug];
          const Icon = k.icon;
          return (
            <div className={`admin-dashboard-kpi-card-${slug}`} key={slug}>
              <div className="admin-dashboard-kpi-header">
                <span className={`admin-dashboard-kpi-label-${slug}`}>{k.label}</span>
                <span className={`admin-dashboard-kpi-icon-${slug}`}>
                  <Icon size={16} />
                </span>
              </div>
              <div className={`admin-dashboard-kpi-value-${slug}`}>{k.value}</div>
            </div>
          );
        })}
      </div>

      <div className="admin-dashboard-revenue-chart-card">
        <div className="admin-dashboard-revenue-chart-card-body">
          <div className="admin-dashboard-revenue-chart-header">
            <TrendingUp size={16} className="admin-dashboard-revenue-chart-header-icon" />
            <h2 className="admin-dashboard-revenue-chart-title">Revenue by month</h2>
          </div>
          <div className="admin-dashboard-revenue-chart-container">
            {chartData.length === 0 ? (
              <div className="admin-dashboard-revenue-chart-empty">No paid bookings yet.</div>
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

      <div className="admin-dashboard-two-col-grid">
        {BOXES.map((box) => {
          if (box === 'inventory') {
            return <InventoryBox key={box} billboards={billboards} />;
          }
          if (box === 'booking-pipeline') {
            return <BookingPipelineBox key={box} bookings={bookings} />;
          }
          return null;
        })}
      </div>
    </AdminShell>
  );
}
