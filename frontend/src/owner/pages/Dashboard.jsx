import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CalendarCheck, Clock, DollarSign, Megaphone, Wallet } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Dashboard.css';


const BOXES = ['my-billboards','recent-bookings'];

// "Recent booking requests" box
function RecentBookingsBox({ bookings, onViewAll }) {
  return (
    <div className="dashboard-recent-bookings-card">
      <div className="dashboard-recent-bookings-card-body">
        <div className="dashboard-recent-bookings-header">
          <h2 className="dashboard-recent-bookings-title">Recent booking requests</h2>
          <button type="button" className="dashboard-view-all-btn" onClick={onViewAll}>
            View all
          </button>
        </div>
        <div className="dashboard-recent-bookings-list">
          {bookings.slice(0, 5).map((bk) => (
            <div
              key={bk.id}
              className="dashboard-recent-bookings-item"
            >
              <div>
                <div className="dashboard-recent-bookings-item-title">{bk.user?.name}</div>
                <div className="dashboard-recent-bookings-item-sub">
                  {bk.billboard?.title} &middot; {bk.start_date?.slice(0, 10)} &rarr; {bk.end_date?.slice(0, 10)}
                </div>
              </div>
              <span className="dashboard-recent-bookings-badge">{bk.status}</span>
            </div>
          ))}
          {bookings.length === 0 && <p className="dashboard-recent-bookings-empty">No booking requests yet.</p>}
        </div>
      </div>
    </div>
  );
}

// "My billboards" box
function MyBillboardsBox({ billboards, onManage }) {
  return (
    <div className="dashboard-my-billboards-card">
      <div className="dashboard-my-billboards-card-body">
        <div className="dashboard-my-billboards-header">
          <h2 className="dashboard-my-billboards-title">My billboards</h2>
          <button type="button" className="dashboard-manage-btn" onClick={onManage}>
            Manage
          </button>
        </div>
        <div className="dashboard-my-billboards-list">
          {billboards.slice(0, 5).map((b) => (
            <div
              key={b.id}
              className="dashboard-my-billboards-item"
            >
              <div>
                <div className="dashboard-my-billboards-item-title">{b.title}</div>
                <div className="dashboard-my-billboards-item-sub">
                  {b.address}
                  {b.listing_status && b.listing_status !== 'approved' && (
                    <span className="dashboard-my-billboards-listing-tag">
                      {' · '}
                      {b.listing_status === 'pending_payment' ? 'payment due'
                        : b.listing_status === 'pending_review' ? 'under review'
                          : 'rejected'}
                    </span>
                  )}
                </div>
              </div>
              <div className="dashboard-my-billboards-price">
                <div className="dashboard-my-billboards-price-amount">
                  {b.pricing_mode === 'monthly' ? `${formatBDT(b.monthly_rate)}/mo` : `${formatBDT(b.daily_rate)}/day`}
                </div>
                <span className="dashboard-my-billboards-badge">{b.status}</span>
              </div>
            </div>
          ))}
          {billboards.length === 0 && (
            <p className="dashboard-my-billboards-empty">
              You haven&apos;t listed any billboards yet.{' '}
              <button
                type="button"
                className="dashboard-add-first-btn"
                onClick={onManage}
              >
                Add your first
              </button>
              .
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

export default function OwnerDashboard() {
    usePageTitle('Owner Dashboard');

  const navigate = useNavigate();
  const [billboards, setBillboards] = useState([]);
  const [bookings, setBookings] = useState([]);
  const [earnings, setEarnings] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get('/owner/billboards'),
      api.get('/owner/bookings'),
      api.get('/owner/reports/transactions'),
    ])
      .then(([bbRes, bkRes, txRes]) => {
        setBillboards(bbRes.data.data.data);
        setBookings(bkRes.data.data);
        setEarnings(txRes.data.data.totals);
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
  // Cash actually collected, not the contract value. collected_amount comes
  // from the API (OwnerBookingController -> SharedRevenueRecognitionService):
  // 0 while a booking still awaits admin or owner approval, the advance once
  // both are in, the full amount once the balance is paid. Summing it here
  // keeps this tile on the exact same rule as the admin revenue report.
  const revenue = bookings.reduce((s, b) => s + Number(b.collected_amount ?? 0), 0);

  // Strictly the money sitting with the admin: paid in full, proof of
  // installation uploaded, waiting to be verified. The same figure as the
  // Revenue page's card and the "Awaiting Admin" tab on Booking Requests -
  // three screens, one number.
  //
  // Deliberately NOT everything the owner is owed: an advance-only booking, or
  // one paid in full with no proof uploaded, is waiting on the client or on the
  // owner, not on a payout, so it is not counted here. "Revenue (BDT)" beside
  // it stays the lifetime total.
  const awaitingVerification = Number(earnings?.awaiting_verification ?? 0);

  const kpis = [
    { slug: 'my-billboards', label: 'My billboards', value: billboards.length, icon: Megaphone },
    { slug: 'pending-requests', label: 'Pending requests', value: pending.length, icon: Clock, accent: 'warning' },
    { slug: 'confirmed-bookings', label: 'Confirmed bookings', value: inProgress.length, icon: CalendarCheck, accent: 'success' },
    // The only tile with something behind it: the payments that add up to
    // this figure (owner/pages/Transactions.jsx).
    { slug: 'revenue', label: 'Revenue (BDT)', value: formatBDT(revenue), icon: DollarSign, to: '/owner/revenue' },
    { slug: 'awaiting-verification', label: 'Awaiting verification', value: formatBDT(awaitingVerification), icon: Wallet, to: '/owner/revenue' },
  ];

  return (
    <OwnerShell title="Dashboard">
      <div className="dashboard-kpi-grid">
        {kpis.map((k) => {
          const Icon = k.icon;

          const cardBody = (
            <>
              <div className="dashboard-kpi-header">
                <span className="dashboard-kpi-label">{k.label}</span>
                <span className={`dashboard-kpi-icon ${k.accent || ''}`}>
                  <Icon size={16} />
                </span>
              </div>
              <div className="dashboard-kpi-value">{k.value}</div>
              {k.to && <span className="dashboard-kpi-drill-down-hint">See transactions</span>}
            </>
          );

          // A tile with somewhere to go is the same card inside a link, so the
          // card keeps the exact look the other three have.
          return k.to
            ? (
              <Link to={k.to} className={`dashboard-kpi-link-${k.slug}`} key={k.label}>
                <div className={`dashboard-kpi-card dashboard-kpi-card-${k.slug}`}>{cardBody}</div>
              </Link>
            )
            : <div className={`dashboard-kpi-card dashboard-kpi-card-${k.slug}`} key={k.label}>{cardBody}</div>;
        })}
      </div>

      <div className="dashboard-two-col-grid">
        {BOXES.map((box) => {
          if (box === 'recent-bookings') {
            return <RecentBookingsBox key={box} bookings={bookings} onViewAll={() => navigate('/owner/bookings')} />;
          }
          if (box === 'my-billboards') {
            return <MyBillboardsBox key={box} billboards={billboards} onManage={() => navigate('/owner/billboards')} />;
          }
          return null;
        })}
      </div>
    </OwnerShell>
  );
}
