import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './MyBillboards.css';

const TYPES = ['unipole', 'multipole', 'gantry', 'rooftop', 'freestanding', 'static', 'backlit', 'frontlit', 'led', 'neon', 'wall'];
const STATUSES = ['available', 'booked', 'hidden'];

const BLANK_FORM = {
  title: '', description: '', latitude: '', longitude: '', address: '', size: '',
  type: 'unipole', daily_rate: '', monthly_rate: '', pricing_mode: 'daily',
  permit_expiry_date: '',
};

// Shown as a banner when the browser returns from the SSLCommerz hosted page.
const LISTING_BANNER = {
  success: { kind: 'success', text: 'Listing fee received. Your board is now awaiting admin review.' },
  failed: { kind: 'error', text: 'Payment did not go through. Nothing was charged - use "Pay listing fee" below to try again.' },
  cancelled: { kind: 'info', text: 'Payment cancelled. Nothing was charged - the board is saved and unpaid.' },
};

const LISTING_LABEL = {
  pending_payment: 'Payment due',
  pending_review: 'Under review',
  approved: 'Approved',
  rejected: 'Rejected',
};

// Keep in step with StoreBillboardListingRequest (photo max:5120, permit_document max:10240).
const MAX_PHOTO_MB = 5;
const MAX_PERMIT_MB = 10;

// Pull a human message out of an axios error, even when the body isn't clean
// JSON (e.g. PHP's "POST Content-Length exceeds the limit" 413, which arrives
// with an HTML warning prepended so err.response.data is a raw string).
function errorMessage(err, fallback) {
  const status = err.response?.status;
  const data = err.response?.data;
  if (status === 413 || (typeof data === 'string' && /post.*too large|content-length/i.test(data))) {
    return `Those files are too large to upload. Keep the photo under ${MAX_PHOTO_MB} MB and the permit document under ${MAX_PERMIT_MB} MB.`;
  }
  if (data && typeof data === 'object') {
    if (data.errors) return Object.values(data.errors).flat().join(' ');
    if (data.message) return data.message;
  }
  return fallback;
}

export default function OwnerMyBillboards() {
    usePageTitle('My Billboards');

  const [billboards, setBillboards] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState('');
  const [photoFile, setPhotoFile] = useState(null);
  const [permitDocFile, setPermitDocFile] = useState(null);
  const [listingFee, setListingFee] = useState(5000);
  const [payingId, setPayingId] = useState(null);
  const [feeError, setFeeError] = useState('');
  const [banner, setBanner] = useState(() => {
    const result = new URLSearchParams(window.location.search).get('listing');
    return LISTING_BANNER[result] || null;
  });

  function load() {
    setLoading(true);
    api.get('/owner/billboards')
      .then((res) => setBillboards(res.data.data.data))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  useEffect(() => {
    api.get('/settings/public')
      .then((res) => setListingFee(res.data.data.listing_fee ?? 5000))
      .catch(() => {});
  }, []);

  function dismissBanner() {
    setBanner(null);
    const url = new URL(window.location.href);
    url.searchParams.delete('listing');
    window.history.replaceState({}, '', url);
  }

  function startEdit(form) {
    setEditing(form);
    setError('');
    setPhotoFile(null);
    setPermitDocFile(null);
  }

  async function handleDelete(id) {
    if (!window.confirm('Delete this billboard?')) return;
    await api.delete(`/owner/billboards/${id}`);
    load();
  }

  // Send the browser to the SSLCommerz hosted page for a pending listing fee.
  async function payListingFee(paymentId, billboardId) {
    setFeeError('');
    setPayingId(billboardId);
    try {
      const res = await api.post(`/owner/listing-payments/${paymentId}/checkout`);
      window.location.assign(res.data.data.gateway_url);
    } catch (err) {
      setFeeError(errorMessage(err, 'Could not start checkout. Please try again.'));
      setPayingId(null);
    }
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    // Editing an existing board: plain JSON update, no fee, no file swap.
    if (editing.id) {
      try {
        await api.put(`/owner/billboards/${editing.id}`, editing);
        setEditing(null);
        load();
      } catch (err) {
        setError(errorMessage(err, 'Could not save this billboard.'));
      }
      return;
    }

    // New listing: multipart (photo + permit document), then straight to checkout.
    if (!photoFile) { setError('Attach a photo of the board.'); return; }
    if (!permitDocFile) { setError('Attach the permit document.'); return; }
    if (photoFile.size > MAX_PHOTO_MB * 1024 * 1024) {
      setError(`Board photo is ${(photoFile.size / 1024 / 1024).toFixed(1)} MB — please use an image under ${MAX_PHOTO_MB} MB.`);
      return;
    }
    if (permitDocFile.size > MAX_PERMIT_MB * 1024 * 1024) {
      setError(`Permit document is ${(permitDocFile.size / 1024 / 1024).toFixed(1)} MB — please use a file under ${MAX_PERMIT_MB} MB.`);
      return;
    }

    try {
      const form = new FormData();
      Object.entries(editing).forEach(([k, v]) => form.append(k, v ?? ''));
      form.append('photo', photoFile);
      form.append('permit_document', permitDocFile);
      const res = await api.post('/owner/billboards', form);
      const payment = res.data.data.listing_payment;
      const billboard = res.data.data.billboard;
      await payListingFee(payment.id, billboard.id);
    } catch (err) {
      setError(errorMessage(err, 'Could not save this billboard.'));
    }
  }

  return (
    <OwnerShell title="My Billboards">
      {banner && (
        <div className={`billboards-listing-banner billboards-listing-banner-${banner.kind}`} role="status">
          <span>{banner.text}</span>
          <button type="button" className="billboards-listing-banner-dismiss" onClick={dismissBanner} aria-label="Dismiss">
            &times;
          </button>
        </div>
      )}

      <div className="billboards-section-header">
        <p className="billboards-muted">{billboards.length} billboard{billboards.length === 1 ? '' : 's'} listed</p>
        {!editing && (
          <button type="button" className="billboards-list-new-billboard-btn" onClick={() => startEdit({ ...BLANK_FORM })}>
            <Plus size={16} /> List new billboard
          </button>
        )}
      </div>

      {feeError && <p className="billboards-error-text">{feeError}</p>}

      {editing && (
        <div className="billboards-card billboards-mb-4">
          <div className="billboards-card-body">
            <form onSubmit={handleSubmit}>
              <h3 className="billboards-section-title">{editing.id ? 'Edit billboard' : 'List new billboard'}</h3>
              {error && <p className="billboards-error-text">{error}</p>}

              <div className="billboards-form-row">
                <label className="billboards-form-label">Title</label>
                <input className="billboards-form-input" value={editing.title} onChange={(e) => setEditing({ ...editing, title: e.target.value })} required />
              </div>

              <div className="billboards-form-row">
                <label className="billboards-form-label">Address</label>
                <input className="billboards-form-input" value={editing.address} onChange={(e) => setEditing({ ...editing, address: e.target.value })} required />
              </div>

              <div className="billboards-form-grid billboards-form-grid-2">
                <div>
                  <label className="billboards-form-label">Latitude</label>
                  <input className="billboards-form-input" type="number" step="0.0000001" value={editing.latitude} onChange={(e) => setEditing({ ...editing, latitude: e.target.value })} required />
                </div>
                <div>
                  <label className="billboards-form-label">Longitude</label>
                  <input className="billboards-form-input" type="number" step="0.0000001" value={editing.longitude} onChange={(e) => setEditing({ ...editing, longitude: e.target.value })} required />
                </div>
              </div>

              <div className="billboards-form-grid billboards-form-grid-2">
                <div>
                  <label className="billboards-form-label">Type</label>
                  <select className="billboards-form-select" value={editing.type} onChange={(e) => setEditing({ ...editing, type: e.target.value })}>
                    {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                </div>
                <div>
                  <label className="billboards-form-label">Size</label>
                  <input className="billboards-form-input" value={editing.size} onChange={(e) => setEditing({ ...editing, size: e.target.value })} required />
                </div>
              </div>

              <div className="billboards-form-grid billboards-form-grid-3">
                <div>
                  <label className="billboards-form-label">Pricing mode</label>
                  <select className="billboards-form-select" value={editing.pricing_mode} onChange={(e) => setEditing({ ...editing, pricing_mode: e.target.value })}>
                    <option value="daily">daily</option>
                    <option value="monthly">monthly</option>
                  </select>
                </div>
                <div>
                  <label className="billboards-form-label">Daily rate (BDT)</label>
                  <input className="billboards-form-input" type="number" value={editing.daily_rate} onChange={(e) => setEditing({ ...editing, daily_rate: e.target.value })} required />
                </div>
                <div>
                  <label className="billboards-form-label">Monthly rate (BDT)</label>
                  <input className="billboards-form-input" type="number" value={editing.monthly_rate || ''} onChange={(e) => setEditing({ ...editing, monthly_rate: e.target.value })} />
                </div>
              </div>

              {editing.id ? (
                <div className="billboards-form-grid billboards-form-grid-3">
                  <div>
                    <label className="billboards-form-label">Status</label>
                    <select className="billboards-form-select" value={editing.status} onChange={(e) => setEditing({ ...editing, status: e.target.value })}>
                      {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="billboards-form-label">Rating</label>
                    <input className="billboards-form-input" type="number" min="0" max="5" step="0.1" value={editing.rating ?? 0} onChange={(e) => setEditing({ ...editing, rating: e.target.value })} />
                  </div>
                  <div>
                    <label className="billboards-form-label">Permit expiry date</label>
                    <input className="billboards-form-input" type="date" value={editing.permit_expiry_date ? editing.permit_expiry_date.slice(0, 10) : ''} onChange={(e) => setEditing({ ...editing, permit_expiry_date: e.target.value })} required />
                  </div>
                </div>
              ) : (
                <>
                  <div className="billboards-form-grid billboards-form-grid-3">
                    <div>
                      <label className="billboards-permit-expiry-label">Permit expiry date</label>
                      <input className="billboards-form-input" type="date" value={editing.permit_expiry_date || ''} onChange={(e) => setEditing({ ...editing, permit_expiry_date: e.target.value })} required />
                    </div>
                    <div>
                      <label className="billboards-photo-label">Board photo <span className="billboards-file-hint">(image, max {MAX_PHOTO_MB} MB)</span></label>
                      <input className="billboards-photo-input" type="file" accept="image/*" onChange={(e) => setPhotoFile(e.target.files[0] || null)} required />
                    </div>
                    <div>
                      <label className="billboards-permit-document-label">Permit document <span className="billboards-file-hint">(PDF or image, max {MAX_PERMIT_MB} MB)</span></label>
                      <input className="billboards-permit-document-input" type="file" accept=".pdf,image/*" onChange={(e) => setPermitDocFile(e.target.files[0] || null)} required />
                    </div>
                  </div>
                  <p className="billboards-listing-fee-notice">
                    A one-time <strong>{formatBDT(listingFee)}</strong> listing fee applies. After you save, you&apos;ll continue to
                    secure SSLCommerz checkout; the board goes for admin review once the fee is paid.
                  </p>
                </>
              )}

              <div className="billboards-form-row">
                <label className="billboards-form-label">Description</label>
                <textarea className="billboards-form-textarea" value={editing.description || ''} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
              </div>

              <div className="billboards-flex billboards-flex-gap-2">
                <button type="submit" className="billboards-save-btn">
                  {editing.id ? 'Save' : `Save & pay ${formatBDT(listingFee)}`}
                </button>
                <button type="button" className="billboards-cancel-btn" onClick={() => setEditing(null)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}
{/* Listed Board details table */}
      {loading ? (
        <p className="billboards-muted">Loading...</p>
      ) : (
        <div className="billboards-card">
          <table className="billboards-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Type</th>
                <th>Price</th>
                <th>Listing</th>
                <th>Status</th>
                <th>Permit expiry</th>
                <th className="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {billboards.map((b) => {
                const pendingFee = (b.listing_payments || []).find((p) => p.status === 'pending');
                return (
                  <tr key={b.id}>
                    <td>
                      <div className="row-title">{b.title}</div>
                      <div className="row-sub">{b.address}</div>
                    </td>
                    <td className="billboards-type-cell">{b.type}</td>
                    <td>{b.pricing_mode === 'monthly' ? `${formatBDT(b.monthly_rate)}/mo` : `${formatBDT(b.daily_rate)}/day`}</td>
                    <td>
                      <span className={`billboards-listing-badge-${(b.listing_status || 'approved').replace(/_/g, '-')}`}>
                        {LISTING_LABEL[b.listing_status] || b.listing_status || 'Approved'}
                      </span>
                      {b.listing_status === 'rejected' && b.listing_rejection_reason && (
                        <div className="billboards-listing-reject-reason">{b.listing_rejection_reason}</div>
                      )}
                    </td>
                    <td><span className="billboards-badge billboards-badge-neutral">{b.status}</span></td>
                    <td>{b.permit_expiry_date ? b.permit_expiry_date.slice(0, 10) : 'N/A'}</td>
                    <td className="text-right">
                      <div className="billboards-flex billboards-flex-gap-2 billboards-justify-end">
                        {b.listing_status === 'pending_payment' && pendingFee && (
                          <button
                            className="billboards-pay-listing-fee-btn"
                            disabled={payingId === b.id}
                            onClick={() => payListingFee(pendingFee.id, b.id)}
                          >
                            {payingId === b.id ? 'Redirecting…' : 'Pay listing fee'}
                          </button>
                        )}
                        <button className="billboards-edit-btn" onClick={() => startEdit({ ...b })}>Edit</button>
                        <button className="billboards-delete-btn" onClick={() => handleDelete(b.id)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                );
              })}
              {billboards.length === 0 && (
                <tr>
                  <td colSpan={7} className="billboards-table-empty">
                    You haven&apos;t listed any billboards yet. Click &quot;List new billboard&quot; to add one.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </OwnerShell>
  );
}
