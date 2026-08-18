import { Fragment, useEffect, useState } from 'react';
import { Check, ChevronDown, ChevronUp, X } from 'lucide-react';
import api from '../../api/axios';
import AdminShell from '../../components/AdminShell';
import { formatBDT } from '../../utils/formatPrice';
import './admin.css';

const STATUSES = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];

export default function BookingsPage() {
  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('pending');
  const [rejectingId, setRejectingId] = useState(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [expandedId, setExpandedId] = useState(null);

  function load() {
    setLoading(true);
    api.get('/admin/bookings')
      .then((res) => setBookings(res.data.data))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

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

  async function handleRecordBalance(id) {
    try {
      await api.post(`/admin/bookings/${id}/balance-payment`, { method: 'cash' });
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not record balance payment.');
    }
  }

  const groups = Object.fromEntries(STATUSES.map((s) => [s, bookings.filter((b) => b.status === s)]));
  const rows = groups[activeTab] || [];

  return (
    <AdminShell title="Bookings">
      {error && <p className="error-text">{error}</p>}

      {loading ? (
        <p className="muted">Loading...</p>
      ) : (
        <>
          <div className="tabs-list">
            {STATUSES.map((s) => (
              <button
                key={s}
                type="button"
                className={`tab-trigger ${activeTab === s ? 'active' : ''}`}
                onClick={() => setActiveTab(s)}
              >
                {s} ({groups[s].length})
              </button>
            ))}
          </div>

          {activeTab === 'pending' && (
            <p className="muted mb-2" style={{ fontSize: 13 }}>
              Advance payment is confirmed before a request reaches this list. Reviewed within 24 hours.
            </p>
          )}

          <div className="card">
            <table className="admin-table">
              <thead>
                <tr>
                  <th style={{ width: 40 }} />
                  <th>Client</th>
                  <th>Billboard</th>
                  <th>Dates</th>
                  <th>Total</th>
                  <th>Payment</th>
                  <th className="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((bk) => {
                  const advancePayment = bk.payments?.find((p) => p.payment_type === 'advance');
                  const balancePayment = bk.payments?.find((p) => p.payment_type === 'balance');
                  const expanded = expandedId === bk.id;
                  return (
                    <Fragment key={bk.id}>
                      <tr>
                        <td>
                          <button
                            type="button"
                            className="btn btn-ghost btn-icon"
                            onClick={() => setExpandedId(expanded ? null : bk.id)}
                          >
                            {expanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                          </button>
                        </td>
                        <td>
                          <div className="row-title">{bk.user?.name || 'N/A'}</div>
                          <div className="row-sub">#{bk.id}</div>
                        </td>
                        <td>{bk.billboard?.title || 'N/A'}</td>
                        <td style={{ fontSize: 13 }}>{bk.start_date} → {bk.end_date}</td>
                        <td style={{ fontWeight: 600 }}>{formatBDT(bk.total_amount)}</td>
                        <td>
                          {advancePayment ? (
                            <span className="badge badge-neutral">advance {advancePayment.status}</span>
                          ) : 'N/A'}
                        </td>
                        <td className="text-right">
                          {activeTab === 'pending' ? (
                            rejectingId === bk.id ? (
                              <div className="flex flex-gap-2 justify-end items-center">
                                <input
                                  className="form-input"
                                  style={{ width: 160, padding: '6px 10px', fontSize: 12 }}
                                  placeholder="Rejection reason"
                                  value={reason}
                                  onChange={(e) => setReason(e.target.value)}
                                />
                                <button className="btn btn-primary btn-sm" onClick={() => submitReject(bk.id)}>Confirm</button>
                                <button className="btn btn-outline btn-sm" onClick={() => setRejectingId(null)}>Cancel</button>
                              </div>
                            ) : (
                              <div className="flex flex-gap-2 justify-end">
                                <button className="btn btn-primary btn-sm" onClick={() => handleApprove(bk.id)}>
                                  <Check size={14} /> Approve
                                </button>
                                <button
                                  className="btn btn-outline btn-sm"
                                  onClick={() => { setRejectingId(bk.id); setReason(''); }}
                                >
                                  <X size={14} /> Reject
                                </button>
                              </div>
                            )
                          ) : activeTab === 'approved' ? (
                            balancePayment?.status === 'paid' ? (
                              <span className="badge badge-success">balance paid</span>
                            ) : (
                              <button className="btn btn-outline btn-sm" onClick={() => handleRecordBalance(bk.id)}>
                                Record balance (cash)
                              </button>
                            )
                          ) : activeTab === 'rejected' ? (
                            <span className="row-sub">{bk.rejection_reason || 'N/A'}</span>
                          ) : (
                            <span className="row-sub">N/A</span>
                          )}
                        </td>
                      </tr>
                      {expanded && (
                        <tr>
                          <td colSpan={7} style={{ background: '#f8fafc' }}>
                            <div className="flex flex-gap-3" style={{ padding: '8px 0', flexWrap: 'wrap' }}>
                              {bk.creative_url && (
                                <img
                                  src={bk.creative_url}
                                  alt="Ad creative"
                                  style={{ width: 128, height: 96, borderRadius: 6, border: '1px solid #e2e8f0', objectFit: 'cover' }}
                                />
                              )}
                              <div style={{ flex: 1, display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, fontSize: 13 }}>
                                <div>
                                  <div className="mini-stat-label">Brand</div>
                                  <div>{bk.brand_name || 'N/A'}</div>
                                </div>
                                <div>
                                  <div className="mini-stat-label">Category</div>
                                  <div>{bk.ad_category || 'N/A'}</div>
                                </div>
                                <div style={{ gridColumn: 'span 3' }}>
                                  <div className="mini-stat-label">Campaign description</div>
                                  <div>{bk.campaign_description || 'N/A'}</div>
                                </div>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={7} style={{ padding: 32, textAlign: 'center', color: '#64748b' }}>
                      No {activeTab} bookings.
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