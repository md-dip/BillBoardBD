import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CalendarCheck, Clock, DollarSign, Megaphone } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Dashboard.css';

export default function OwnerDashboard() {
  const navigate = useNavigate();
  const [billboards, setBillboards] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([api.get('/owner/billboards'), api.get('/owner/bookings')])
      .then(([bbRes, bkRes]) => {
        setBillboards(bbRes.data.data.data);
        setBookings(bkRes.data.data);
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <OwnerShell title="Dashboard">
        <p className="dashboard-muted">Loading...</p>
      </OwnerShell>
    );
  }

  const pending = bookings.filter((b) => b.status === 'pending_owner_approval');
  const inProgress = bookings.filter((b) => ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'].includes(b.status));
  const revenue = bookings
    .filter((b) => ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'].includes(b.status))
    .reduce((s, b) => s + Number(b.total_amount), 0);

  const kpis = [
    { slug: 'my-billboards', label: 'My billboards', value: billboards.length, icon: Megaphone },
    { slug: 'pending-requests', label: 'Pending requests', value: pending.length, icon: Clock, accent: 'warning' },
    { slug: 'confirmed-bookings', label: 'Confirmed bookings', value: inProgress.length, icon: CalendarCheck, accent: 'success' },
    { slug: 'revenue', label: 'Revenue (BDT)', value: formatBDT(revenue), icon: DollarSign },
  ];

  return (
    <OwnerShell title="Dashboard">
      <div className="dashboard-kpi-grid">
        {kpis.map((k) => {
          const Icon = k.icon;
          return (
            <div className={`dashboard-kpi-card dashboard-kpi-card-${k.slug}`} key={k.label}>
              <div className="dashboard-kpi-header">
                <span className="dashboard-kpi-label">{k.label}</span>
                <span className={`dashboard-kpi-icon ${k.accent || ''}`}>
                  <Icon size={16} />
                </span>
              </div>
              <div className="dashboard-kpi-value">{k.value}</div>
            </div>
          );
        })}
      </div>

      <div className="dashboard-two-col-grid">
        <div className="dashboard-recent-bookings-card">
          <div className="dashboard-recent-bookings-card-body">
            <div className="dashboard-section-header">
              <h2 className="dashboard-section-title">Recent booking requests</h2>
              <button type="button" className="dashboard-btn dashboard-btn-outline dashboard-btn-sm" onClick={() => navigate('/owner/bookings')}>
                View all
              </button>
            </div>
            <div className="dashboard-row-list">
              {bookings.slice(0, 5).map((bk) => (
                <div
                  key={bk.id}
                  className="dashboard-row-item"
                >
                  <div>
                    <div className="dashboard-row-title">{bk.user?.name}</div>
                    <div className="dashboard-row-sub">
                      {bk.billboard?.title} &middot; {bk.start_date?.slice(0, 10)} &rarr; {bk.end_date?.slice(0, 10)}
                    </div>
                  </div>
                  <span className="dashboard-badge dashboard-badge-neutral">{bk.status}</span>
                </div>
              ))}
              {bookings.length === 0 && <p className="dashboard-muted">No booking requests yet.</p>}
            </div>
          </div>
        </div>

        <div className="dashboard-my-billboards-card">
          <div className="dashboard-my-billboards-card-body">
            <div className="dashboard-section-header">
              <h2 className="dashboard-section-title">My billboards</h2>
              <button type="button" className="dashboard-btn dashboard-btn-outline dashboard-btn-sm" onClick={() => navigate('/owner/billboards')}>
                Manage
              </button>
            </div>
            <div className="dashboard-row-list">
              {billboards.slice(0, 5).map((b) => (
                <div
                  key={b.id}
                  className="dashboard-row-item"
                >
                  <div>
                    <div className="dashboard-row-title">{b.title}</div>
                    <div className="dashboard-row-sub">{b.address}</div>
                  </div>
                  <div className="dashboard-row-price">
                    <div className="dashboard-row-price-amount">
                      {b.pricing_mode === 'monthly' ? `${formatBDT(b.monthly_rate)}/mo` : `${formatBDT(b.daily_rate)}/day`}
                    </div>
                    <span className="dashboard-badge dashboard-badge-neutral">{b.status}</span>
                  </div>
                </div>
              ))}
              {billboards.length === 0 && (
                <p className="dashboard-muted">
                  You haven&apos;t listed any billboards yet.{' '}
                  <button
                    type="button"
                    className="dashboard-add-first-btn"
                    onClick={() => navigate('/owner/billboards')}
                  >
                    Add your first
                  </button>
                  .
                </p>
              )}
            </div>
          </div>
        </div>
      </div>
    </OwnerShell>
  );
}
