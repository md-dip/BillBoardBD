import { Fragment, useEffect, useState } from 'react';
import { Check, ChevronDown, ChevronUp, Upload, X } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './BookingRequests.css';

const STATUSES = ['pending_owner_approval', 'confirmed', 'paid_in_full', 'pending_proof_review', 'active', 'rejected'];

const STATUS_LABEL = {
  pending_owner_approval: 'new requests',
  confirmed: 'confirmed',
  paid_in_full: 'ready to install',
  pending_proof_review: 'awaiting admin',
  active: 'live',
  rejected: 'rejected',
};

export default function OwnerBookingRequests() {
  const [bookings, setBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('pending_owner_approval');
  const [rejectingId, setRejectingId] = useState(null);
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');
  const [expandedId, setExpandedId] = useState(null);
  const [uploadingId, setUploadingId] = useState(null);
  const [photos, setPhotos] = useState(null);

  function load() {
    setLoading(true);
    api.get('/owner/bookings')
      .then((res) => setBookings(res.data.data))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  async function handleApprove(id) {
    setError('');
    try {
      await api.patch(`/owner/bookings/${id}/approve`);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not accept this booking.');
    }
  }

  async function submitReject(id) {
    setError('');
    try {
      await api.patch(`/owner/bookings/${id}/reject`, { rejection_reason: reason });
      setRejectingId(null);
      setReason('');
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not decline this booking.');
    }
  }

  async function submitProof(id) {
    setError('');
    if (!photos || photos.length === 0) {
      setError('Choose at least one photo.');
      return;
    }
    try {
      const form = new FormData();
      Array.from(photos).forEach((file) => form.append('photos[]', file));
      await api.post(`/owner/bookings/${id}/proof`, form);
      setUploadingId(null);
      setPhotos(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not upload proof of posting.');
    }
  }

  const groups = Object.fromEntries(STATUSES.map((s) => [s, bookings.filter((b) => b.status === s)]));
  const rows = groups[activeTab] || [];

  return (
    <OwnerShell title="Booking Requests">
      {error && <p className="bookings-error-text">{error}</p>}

      {loading ? (
        <p className="bookings-muted">Loading...</p>
      ) : (
        <>
          <div className="bookings-tabs-list">
            {STATUSES.map((s) => (
              <button
                key={s}
                type="button"
                className={`bookings-tab-trigger ${activeTab === s ? 'active' : ''}`}
                onClick={() => setActiveTab(s)}
              >
                {STATUS_LABEL[s]} ({groups[s].length})
              </button>
            ))}
          </div>

          {activeTab === 'pending_owner_approval' && (
            <p className="bookings-muted bookings-mb-2 bookings-tab-hint-text">
              Admin has already reviewed these. Accept to confirm the booking and start the final-payment countdown.
            </p>
          )}
          {activeTab === 'paid_in_full' && (
            <p className="bookings-muted bookings-mb-2 bookings-tab-hint-text">
              Final payment received. Upload a photo once the billboard is installed to send it for admin verification.
            </p>
          )}

          <div className="bookings-card">
            <table className="bookings-table">
              <thead>
                <tr>
                  <th className="bookings-expand-col" />
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
                            className="bookings-btn bookings-btn-ghost bookings-btn-icon"
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
                        <td className="bookings-dates-cell">{bk.start_date?.slice(0, 10)} → {bk.end_date?.slice(0, 10)}</td>
                        <td className="bookings-total-cell">{formatBDT(bk.total_amount)}</td>
                        <td>
                          {balancePayment ? (
                            <span className="bookings-badge bookings-badge-neutral">balance {balancePayment.status}</span>
                          ) : advancePayment ? (
                            <span className="bookings-badge bookings-badge-neutral">advance {advancePayment.status}</span>
                          ) : 'N/A'}
                        </td>
                        <td className="text-right">
                          {activeTab === 'pending_owner_approval' ? (
                            rejectingId === bk.id ? (
                              <div className="bookings-flex bookings-flex-gap-2 bookings-justify-end bookings-items-center">
                                <input
                                  className="bookings-form-input bookings-reject-reason-input"
                                  placeholder="Rejection reason"
                                  value={reason}
                                  onChange={(e) => setReason(e.target.value)}
                                />
                                <button className="bookings-btn bookings-btn-primary bookings-btn-sm" onClick={() => submitReject(bk.id)}>Confirm</button>
                                <button className="bookings-btn bookings-btn-outline bookings-btn-sm" onClick={() => setRejectingId(null)}>Cancel</button>
                              </div>
                            ) : (
                              <div className="bookings-flex bookings-flex-gap-2 bookings-justify-end">
                                <button className="bookings-btn bookings-btn-primary bookings-btn-sm" onClick={() => handleApprove(bk.id)}>
                                  <Check size={14} /> Accept
                                </button>
                                <button
                                  className="bookings-btn bookings-btn-outline bookings-btn-sm"
                                  onClick={() => { setRejectingId(bk.id); setReason(''); }}
                                >
                                  <X size={14} /> Decline
                                </button>
                              </div>
                            )
                          ) : activeTab === 'paid_in_full' ? (
                            uploadingId === bk.id ? (
                              <div className="bookings-flex bookings-flex-gap-2 bookings-justify-end bookings-items-center">
                                <input
                                  type="file"
                                  accept="image/*"
                                  multiple
                                  className="bookings-proof-file-input"
                                  onChange={(e) => setPhotos(e.target.files)}
                                />
                                <button className="bookings-btn bookings-btn-primary bookings-btn-sm" onClick={() => submitProof(bk.id)}>Submit</button>
                                <button className="bookings-btn bookings-btn-outline bookings-btn-sm" onClick={() => { setUploadingId(null); setPhotos(null); }}>Cancel</button>
                              </div>
                            ) : (
                              <button className="bookings-btn bookings-btn-primary bookings-btn-sm" onClick={() => setUploadingId(bk.id)}>
                                <Upload size={14} /> Upload proof
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
                          <td colSpan={7} className="bookings-expanded-row-cell">
                            <div className="bookings-expanded-row-content">
                              {bk.creative_url && (
                                <img
                                  src={bk.creative_url}
                                  alt="Ad creative"
                                  className="bookings-expanded-row-photo"
                                />
                              )}
                              {bk.proof_of_postings?.map((p) => (
                                <img
                                  key={p.id}
                                  src={p.photo_url}
                                  alt="Proof of posting"
                                  title={`Proof: ${p.status}`}
                                  className="bookings-expanded-row-photo"
                                />
                              ))}
                              <div className="bookings-expanded-row-detail-grid">
                                <div>
                                  <div className="bookings-mini-stat-label">Brand</div>
                                  <div>{bk.brand_name || 'N/A'}</div>
                                </div>
                                <div>
                                  <div className="bookings-mini-stat-label">Category</div>
                                  <div>{bk.ad_category || 'N/A'}</div>
                                </div>
                                <div className="bookings-expanded-row-detail-span">
                                  <div className="bookings-mini-stat-label">Campaign description</div>
                                  <div>{bk.campaign_description || 'N/A'}</div>
                                </div>
                                {bk.final_payment_due_at && (
                                  <div>
                                    <div className="bookings-mini-stat-label">Final payment due</div>
                                    <div>{bk.final_payment_due_at.slice(0, 10)}</div>
                                  </div>
                                )}
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
                    <td colSpan={7} className="bookings-table-empty">
                      No bookings in this stage.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </OwnerShell>
  );
}
