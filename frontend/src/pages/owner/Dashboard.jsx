import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CalendarCheck, Clock, DollarSign, Megaphone } from 'lucide-react';
import api from '../../api/axios';
import OwnerShell from '../../components/OwnerShell';
import { formatBDT } from '../../utils/formatPrice';

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
        <p className="muted">Loading...</p>
      </OwnerShell>
    );
  }

  const pending = bookings.filter((b) => b.status === 'pending_owner_approval');
  const inProgress = bookings.filter((b) => ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'].includes(b.status));
  const revenue = bookings
    .filter((b) => ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'].includes(b.status))
    .reduce((s, b) => s + Number(b.total_amount), 0);

  const kpis = [
    { label: 'My billboards', value: billboards.length, icon: Megaphone },
    { label: 'Pending requests', value: pending.length, icon: Clock, accent: 'warning' },
    { label: 'Confirmed bookings', value: inProgress.length, icon: CalendarCheck, accent: 'success' },
    { label: 'Revenue (BDT)', value: formatBDT(revenue), icon: DollarSign },
  ];

  return (
    <OwnerShell title="Dashboard">
      <div className="kpi-grid">
        {kpis.map((k) => {
          const Icon = k.icon;
          return (
            <div className="kpi-card" key={k.label}>
              <div className="kpi-header">
                <span className="kpi-label">{k.label}</span>
                <span className={`kpi-icon ${k.accent || ''}`}>
                  <Icon size={16} />
                </span>
              </div>
              <div className="kpi-value">{k.value}</div>
            </div>
          );
        })}
      </div>

      <div className="two-col-grid">
        <div className="card">
          <div className="card-body">
            <div className="section-header">
              <h2 className="section-title" style={{ margin: 0 }}>Recent booking requests</h2>
              <button type="button" className="btn btn-outline btn-sm" onClick={() => navigate('/owner/bookings')}>
                View all
              </button>
            </div>
            <div className="flex flex-gap-2" style={{ flexDirection: 'column' }}>
              {bookings.slice(0, 5).map((bk) => (
                <div
                  key={bk.id}
                  className="flex items-center"
                  style={{ justifyContent: 'space-between', border: '1px solid #e2e8f0', borderRadius: 8, padding: 12 }}
                >
                  <div>
                    <div className="row-title">{bk.user?.name}</div>
                    <div className="row-sub">
                      {bk.billboard?.title} &middot; {bk.start_date?.slice(0, 10)} &rarr; {bk.end_date?.slice(0, 10)}
                    </div>
                  </div>
                  <span className="badge badge-neutral">{bk.status}</span>
                </div>
              ))}
              {bookings.length === 0 && <p className="muted">No booking requests yet.</p>}
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-body">
            <div className="section-header">
              <h2 className="section-title" style={{ margin: 0 }}>My billboards</h2>
              <button type="button" className="btn btn-outline btn-sm" onClick={() => navigate('/owner/billboards')}>
                Manage
              </button>
            </div>
            <div className="flex flex-gap-2" style={{ flexDirection: 'column' }}>
              {billboards.slice(0, 5).map((b) => (
                <div
                  key={b.id}
                  className="flex items-center"
                  style={{ justifyContent: 'space-between', border: '1px solid #e2e8f0', borderRadius: 8, padding: 12 }}
                >
                  <div>
                    <div className="row-title">{b.title}</div>
                    <div className="row-sub">{b.address}</div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 14, fontWeight: 600 }}>
                      {b.pricing_mode === 'monthly' ? `${formatBDT(b.monthly_rate)}/mo` : `${formatBDT(b.daily_rate)}/day`}
                    </div>
                    <span className="badge badge-neutral">{b.status}</span>
                  </div>
                </div>
              ))}
              {billboards.length === 0 && (
                <p className="muted">
                  You haven&apos;t listed any billboards yet.{' '}
                  <button
                    type="button"
                    onClick={() => navigate('/owner/billboards')}
                    style={{ background: 'none', border: 'none', color: '#2563eb', textDecoration: 'underline', cursor: 'pointer', padding: 0 }}
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
