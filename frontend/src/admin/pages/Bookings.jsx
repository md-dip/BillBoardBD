import { Fragment, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Check, ChevronDown, ChevronUp, FileText, RotateCcw, X } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Bookings.css';

const STATUSES = [
  'pending_admin_review',
  'pending_owner_approval',
  'confirmed',
  'paid_in_full',
  'pending_proof_review',
  'active',
  'rejected',
  'cancelled',
];

// What the SSLCommerz redirect appends to the URL when the admin comes back
// from paying a refund. Read once on mount, then cleared so a page refresh does
// not replay the banner.
const REFUND_RESULT = {
  success: { text: 'Refund sent. The client has been notified.', ok: true },
  failed: { text: 'That refund did not go through. Nothing was charged - you can try again.', ok: false },
  cancelled: { text: 'Refund cancelled. The advance is still owed.', ok: false },
};

const STATUS_LABEL = {
  pending_admin_review: 'pending review',
  pending_owner_approval: 'awaiting owner',
  confirmed: 'confirmed',
  paid_in_full: 'paid in full',
  pending_proof_review: 'proof review',
  active: 'active',
  rejected: 'rejected',
  cancelled: 'cancelled',
};

export default function AdminBookings() {
    usePageTitle('Admin Bookings');

  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('pending_admin_review');
  const [rejectingId, setRejectingId] = useState(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [expandedId, setExpandedId] = useState(null);
  const [refundingId, setRefundingId] = useState(null);
  const [searchParams, setSearchParams] = useSearchParams();
  const refundResult = REFUND_RESULT[searchParams.get('refund')] || null;

  function load() {
    setLoading(true);
    api.get('/admin/bookings')
      .then((res) => setBookings(res.data.data))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  // Land on the Rejected tab when bouncing back from the gateway - that is
  // where the refund was started and where its result belongs.
  useEffect(() => {
    if (searchParams.get('refund')) setActiveTab('rejected');
  }, [searchParams]);

  async function handleApprove(id) {
    setError('');
    try {
      await api.patch(`/admin/bookings/${id}/approve`);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not approve this booking.');
    }
  }

  async function submitReject(id) {
    setError('');
    try {
      await api.patch(`/admin/bookings/${id}/reject`, { rejection_reason: reason });
      setRejectingId(null);
      setReason('');
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not reject this booking.');
    }
  }

  // Hand the browser to SSLCommerz so the admin can pay the refund. The
  // booking is settled by the gateway callback, not here - we only leave.
  async function handleRefund(id) {
    setError('');
    setRefundingId(id);
    try {
      const res = await api.post(`/admin/bookings/${id}/refund/checkout`);
      window.location.href = res.data.data.gateway_url;
    } catch (err) {
      setError(err.response?.data?.message || 'Could not open the refund checkout.');
      setRefundingId(null);
    }
  }

  async function handleRecordBalance(id) {
    try {
      await api.post(`/admin/bookings/${id}/balance-payment`, { method: 'bank' });
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not record balance payment.');
    }
  }

  async function handleVerifyProof(id) {
    setError('');
    try {
      await api.patch(`/admin/bookings/${id}/proof/verify`);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not verify this proof.');
    }
  }

  async function submitRejectProof(id) {
    setError('');
    try {
      await api.patch(`/admin/bookings/${id}/proof/reject`, { rejection_reason: reason });
      setRejectingId(null);
      setReason('');
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not reject this proof.');
    }
  }

  const groups = Object.fromEntries(STATUSES.map((s) => [s, bookings.filter((b) => b.status === s)]));
  const rows = groups[activeTab] || [];

  return (
    <AdminShell title="Bookings">
      {refundResult && (
        <p className={refundResult.ok ? 'admin-bookings-refund-done-text' : 'admin-bookings-refund-failed-text'}>
          {refundResult.text}
          <button type="button" className="admin-bookings-dismiss-btn" onClick={() => setSearchParams({})}>Dismiss</button>
        </p>
      )}

      {error && <p className="admin-bookings-error-text">{error}</p>}

      {loading ? (
        <p className="admin-bookings-muted">Loading...</p>
      ) : (
        <>
          <div className="admin-bookings-tabs-list">
            {STATUSES.map((s) => (
              <button
                key={s}
                type="button"
                className={`admin-bookings-tab-trigger ${activeTab === s ? 'active' : ''}`}
                onClick={() => setActiveTab(s)}
              >
                {STATUS_LABEL[s]} ({groups[s].length})
              </button>
            ))}
          </div>

          {activeTab === 'pending_admin_review' && (
            <p className="admin-bookings-tab-hint-text">
              Advance payment is confirmed before a request reaches this list. Approving forwards it to the billboard owner.
            </p>
          )}
          {activeTab === 'pending_proof_review' && (
            <p className="admin-bookings-tab-hint-text">
              The owner has uploaded installation photos. Verify to make the campaign go live.
            </p>
          )}

          <div className="admin-bookings-table-card">
            <table className="admin-bookings-table">
              <thead>
                <tr>
                  <th className="admin-bookings-expand-col" />
                  <th>Client</th>
                  <th>Billboard</th>
                  <th>Dates</th>
                  <th>Total</th>
                  <th>Payment</th>
                  <th className="admin-bookings-text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((bk) => {
                  const advancePayment = bk.payments?.find((p) => p.payment_type === 'advance');
                  const balancePayment = bk.payments?.find((p) => p.payment_type === 'balance');
                  const refundPayment = bk.payments?.find((p) => p.payment_type === 'refund');
                  const expanded = expandedId === bk.id;
                  return (
                    <Fragment key={bk.id}>
                      <tr>
                        <td>
                          <button
                            type="button"
                            className="admin-bookings-expand-btn"
                            onClick={() => setExpandedId(expanded ? null : bk.id)}
                          >
                            {expanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                          </button>
                        </td>
                        <td>
                          <div className="admin-bookings-row-title">{bk.user?.name || 'N/A'}</div>
                          <div className="admin-bookings-row-sub">#{bk.id}</div>
                        </td>
                        <td>{bk.billboard?.title || 'N/A'}</td>
                        <td className="admin-bookings-dates-cell">{bk.start_date?.slice(0, 10)} → {bk.end_date?.slice(0, 10)}</td>
                        <td className="admin-bookings-total-cell">{formatBDT(bk.total_amount)}</td>
                        <td>
                          {advancePayment ? (
                            <span className="admin-bookings-badge">advance {advancePayment.status}</span>
                          ) : 'N/A'}
                        </td>
                        <td className="admin-bookings-text-right">
                          {activeTab === 'pending_admin_review' ? (
                            rejectingId === bk.id ? (
                              <div className="admin-bookings-inline-form">
                                <input
                                  className="admin-bookings-reject-reason-input"
                                  placeholder="Rejection reason"
                                  value={reason}
                                  onChange={(e) => setReason(e.target.value)}
                                />
                                <button className="admin-bookings-confirm-btn" onClick={() => submitReject(bk.id)}>Confirm</button>
                                <button className="admin-bookings-cancel-btn" onClick={() => setRejectingId(null)}>Cancel</button>
                              </div>
                            ) : (
                              <div className="admin-bookings-row-actions">
                                <button className="admin-bookings-approve-btn" onClick={() => handleApprove(bk.id)}>
                                  <Check size={14} /> Approve
                                </button>
                                <button
                                  className="admin-bookings-reject-btn"
                                  onClick={() => { setRejectingId(bk.id); setReason(''); }}
                                >
                                  <X size={14} /> Reject
                                </button>
                              </div>
                            )
                          ) : activeTab === 'confirmed' ? (
                            balancePayment?.status === 'paid' ? (
                              <span className="admin-bookings-badge-success">balance paid</span>
                            ) : (
                              <button className="admin-bookings-record-balance-btn" onClick={() => handleRecordBalance(bk.id)}>
                                Record balance (bank)
                              </button>
                            )
                          ) : activeTab === 'pending_proof_review' ? (
                            rejectingId === bk.id ? (
                              <div className="admin-bookings-inline-form">
                                <input
                                  className="admin-bookings-reject-reason-input"
                                  placeholder="Rejection reason"
                                  value={reason}
                                  onChange={(e) => setReason(e.target.value)}
                                />
                                <button className="admin-bookings-confirm-btn" onClick={() => submitRejectProof(bk.id)}>Confirm</button>
                                <button className="admin-bookings-cancel-btn" onClick={() => setRejectingId(null)}>Cancel</button>
                              </div>
                            ) : (
                              <div className="admin-bookings-row-actions">
                                <button className="admin-bookings-verify-btn" onClick={() => handleVerifyProof(bk.id)}>
                                  <Check size={14} /> Verify
                                </button>
                                <button
                                  className="admin-bookings-reject-btn"
                                  onClick={() => { setRejectingId(bk.id); setReason(''); }}
                                >
                                  <X size={14} /> Reject
                                </button>
                              </div>
                            )
                          ) : activeTab === 'rejected' ? (
                            <div className="admin-bookings-rejected-cell">
                              <span className="admin-bookings-row-sub">{bk.rejection_reason || 'N/A'}</span>
                              {refundPayment?.status === 'pending' && (
                                <button
                                  className="admin-bookings-refund-btn"
                                  disabled={refundingId === bk.id}
                                  onClick={() => handleRefund(bk.id)}
                                >
                                  <RotateCcw size={14} />
                                  {refundingId === bk.id ? 'Opening...' : `Refund ${formatBDT(refundPayment.amount)}`}
                                </button>
                              )}
                              {refundPayment?.status === 'refunded' && (
                                <span className="admin-bookings-refund-note">advance refunded</span>
                              )}
                              {!refundPayment && (
                                <span className="admin-bookings-row-sub">no advance to refund</span>
                              )}
                            </div>
                          ) : (
                            <span className="admin-bookings-row-sub">N/A</span>
                          )}
                        </td>
                      </tr>
                      {expanded && (
                        <tr>
                          <td colSpan={7} className="admin-bookings-expanded-row-cell">
                            <div className="admin-bookings-expanded-row-content">
                              {bk.creative_url && (
                                <img
                                  src={bk.creative_url}
                                  alt="Ad creative"
                                  className="admin-bookings-expanded-row-photo"
                                />
                              )}
                              {bk.proof_of_postings?.map((p) => (
                                <img
                                  key={p.id}
                                  src={p.photo_url}
                                  alt="Proof of posting"
                                  title={`Proof: ${p.status}`}
                                  className="admin-bookings-expanded-row-photo"
                                />
                              ))}
                              <div className="admin-bookings-expanded-row-detail-grid">
                                <div>
                                  <div className="admin-bookings-mini-stat-label">Brand</div>
                                  <div>{bk.brand_name || 'N/A'}</div>
                                </div>
                                <div>
                                  <div className="admin-bookings-mini-stat-label">Category</div>
                                  <div>{bk.ad_category || 'N/A'}</div>
                                </div>
                                <div className="admin-bookings-expanded-row-detail-span">
                                  <div className="admin-bookings-mini-stat-label">Campaign description</div>
                                  <div>{bk.campaign_description || 'N/A'}</div>
                                </div>
                              </div>
                              {bk.invoices?.length > 0 && (
                                <Link
                                  to={`/admin/bookings/${bk.id}/invoice`}
                                  className="admin-bookings-invoice-link"
                                >
                                  <FileText size={14} /> View invoice
                                </Link>
                              )}
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={7} className="admin-bookings-table-empty">
                      No bookings in this stage.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </AdminShell>
  );
}
