import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CalendarCheck, DollarSign, Megaphone, ShieldAlert, TrendingUp } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Dashboard.css';

function daysUntil(dateStr) {
  return Math.round((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

const KPIS = ['revenue', 'commission', 'pending-bookings', 'permits-expiring'];
const BOXES = ['inventory', 'booking-pipeline'];

// The revenue chart draws one bar per calendar month, never one bar per month
// that happened to earn something - a quiet month is a fact the admin needs to
// see, not a gap to skip over. So the axis is built as an unbroken run of
// months and the API's rows are dropped onto it.
//
// It is never padded out before the platform's first taka: months from before
// the books open are not quiet months, they are months that did not exist.
const MONTH_LABELS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function monthKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

// "2026-09" -> "2026-07" for shift -2. Goes through Date so it rolls over
// years on its own.
function shiftMonth(key, months) {
  const [year, month] = key.split('-').map(Number);
  return monthKey(new Date(year, month - 1 + months, 1));
}

// "2026-09" -> "Sep 26", short enough that a year of them still fits the axis.
function monthLabel(key) {
  const [year, month] = key.split('-');
  return `${MONTH_LABELS[Number(month) - 1]} ${year.slice(2)}`;
}

// "Inventory" box - every mini-stat gets its own fully independent class
// tree (mini-stat / label / value), so editing one stat's color in the CSS
// never touches the others or the "Booking pipeline" box next to it.
function InventoryBox({ billboards }) {
  const live = billboards.filter((b) => (b.listing_status ?? 'approved') === 'approved');
  const stats = [
    { slug: 'total-billboards', label: 'Total billboards', value: live.length },
    { slug: 'available', label: 'Available', value: live.filter((b) => b.status === 'available').length },
    { slug: 'booked', label: 'Booked', value: live.filter((b) => b.status === 'booked').length },
    { slug: 'hidden', label: 'Hidden', value: live.filter((b) => b.status === 'hidden').length },
    { slug: 'pending-listings', label: 'Pending review', value: billboards.filter((b) => b.listing_status === 'pending_review').length },
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

// "Booking pipeline" box - same treatment: every mini-stat fully independent.
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
    usePageTitle('Admin Dashboard');

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
    // Everything the platform keeps: booking commission + the one-time
    // board listing fees owners pay, which have no owner split at all.
    commission: revenue?.totals?.platform_income ?? 0,
    pending: bookings.filter((b) => b.status === 'pending_admin_review').length,
    expiring: billboards.filter((b) => b.permit_expiry_date && daysUntil(b.permit_expiry_date) < 90).length,
  }), [bookings, billboards, revenue]);

  const chartData = useMemo(() => {
    if (!revenue?.rows?.length) return [];

    // The API returns one row per billboard per month; fold them down to a
    // single figure pair per month first.
    const earned = new Map();
    for (const r of revenue.rows) {
      const entry = earned.get(r.month) ?? { revenue: 0, commission: 0 };
      entry.revenue += Number(r.gross);
      entry.commission += Number(r.commission) + Number(r.listing_fees);
      earned.set(r.month, entry);
    }

    const months = [...earned.keys()].sort();
    const thisMonth = monthKey(new Date());

    // Run the axis from the first month that ever earned anything through to
    // today, so every month the platform has traded in gets its own bar.
    const first = months[0];
    const last = [months[months.length - 1], thisMonth].sort().pop();

    const series = [];
    for (let month = first; month <= last; month = shiftMonth(month, 1)) {
      series.push({
        month,
        label: monthLabel(month),
        revenue: 0,
        commission: 0,
        ...earned.get(month),
      });
    }
    return series;
  }, [revenue]);

  if (loading) {
    return (
      <AdminShell title="Dashboard">
        <p className="admin-dashboard-muted">Loading...</p>
      </AdminShell>
    );
  }

  const kpiValues = {
    // The two money tiles drill down into the transactions behind them
    // (admin/pages/Transactions.jsx); the other two are plain figures.
    revenue: { label: 'Total revenue', value: formatBDT(stats.revenue), icon: DollarSign, to: '/admin/revenue' },
    commission: { label: 'Platform commission', value: formatBDT(stats.commission), icon: TrendingUp, to: '/admin/commission' },
    'pending-bookings': { label: 'Pending bookings', value: String(stats.pending), icon: CalendarCheck },
    'permits-expiring': { label: 'Permits expiring <90d', value: String(stats.expiring), icon: ShieldAlert },
  };

  return (
    <AdminShell title="Dashboard">
      <div className="admin-dashboard-kpi-grid">
        {KPIS.map((slug) => {
          const k = kpiValues[slug];
          const Icon = k.icon;

          // Same card either way - a tile that drills down is just wrapped in
          // a link, so its own card styling is untouched.
          const cardBody = (
            <>
              <div className="admin-dashboard-kpi-header">
                <span className={`admin-dashboard-kpi-label-${slug}`}>{k.label}</span>
                <span className={`admin-dashboard-kpi-icon-${slug}`}>
                  <Icon size={16} />
                </span>
              </div>
              <div className={`admin-dashboard-kpi-value-${slug}`}>{k.value}</div>
              {k.to && <span className="admin-dashboard-kpi-drill-down-hint">View transactions</span>}
            </>
          );

          const card = <div className={`admin-dashboard-kpi-card-${slug}`}>{cardBody}</div>;

          // A drill-down tile is the card inside a link; a plain tile is the
          // card itself. Either way the grid item stretches to the row height,
          // so all four boxes stay the same size.
          return k.to
            ? <Link to={k.to} className={`admin-dashboard-kpi-link-${slug}`} key={slug}>{card}</Link>
            : <div className={`admin-dashboard-kpi-card-${slug}`} key={slug}>{cardBody}</div>;
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
                  <XAxis dataKey="label" interval={0} tickMargin={8} />
                  <YAxis tickFormatter={(v) => `৳${(v / 1000).toFixed(0)}k`} />
                  <Tooltip formatter={(v) => formatBDT(v)} />
                  <Legend />
                  <Bar dataKey="revenue" name="Revenue" fill="#2563eb" radius={[6, 6, 0, 0]} />
                  <Bar dataKey="commission" name="Platform income" fill="#93c5fd" radius={[6, 6, 0, 0]} />
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
