import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Billboards.css';

const TYPES = ['unipole', 'multipole', 'gantry', 'rooftop', 'freestanding', 'static', 'backlit', 'frontlit', 'led', 'neon', 'wall'];
const STATUSES = ['available', 'booked', 'hidden'];

const BLANK_FORM = {
  title: '', description: '', latitude: '', longitude: '', address: '', size: '',
  type: 'unipole', daily_rate: '', monthly_rate: '', pricing_mode: 'daily',
  rating: 0, status: 'available', permit_expiry_date: '',
};

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function permitWarning(dateStr) {
  if (!dateStr) return null;
  const today = todayIso();
  const in30 = new Date();
  in30.setDate(in30.getDate() + 30);
  const in30Iso = in30.toISOString().slice(0, 10);
  if (dateStr < today) return 'expired';
  if (dateStr <= in30Iso) return 'near-expiry';
  return null;
}

export default function AdminBillboards() {
  const [billboards, setBillboards] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState('');

  function load() {
    setLoading(true);
    api.get('/admin/billboards')
      .then((res) => setBillboards(res.data.data.data))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  async function handleDelete(id) {
    if (!window.confirm('Delete this billboard?')) return;
    await api.delete(`/admin/billboards/${id}`);
    load();
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    try {
      if (editing.id) {
        await api.put(`/admin/billboards/${editing.id}`, editing);
      } else {
        await api.post('/admin/billboards', editing);
      }
      setEditing(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not save this billboard.');
    }
  }

  return (
    <AdminShell title="Billboards">
      <div className="admin-billboards-header">
        <p className="admin-billboards-muted">{billboards.length} billboards</p>
        {!editing && (
          <button type="button" className="admin-billboards-add-billboard-btn" onClick={() => setEditing({ ...BLANK_FORM })}>
            <Plus size={16} /> Add billboard
          </button>
        )}
      </div>

      {editing && (
        <div className="admin-billboards-form-card">
          <div className="admin-billboards-form-card-body">
            <form onSubmit={handleSubmit}>
              <h3 className="admin-billboards-form-title">{editing.id ? 'Edit billboard' : 'New billboard'}</h3>
              {error && <p className="admin-billboards-error-text">{error}</p>}

              <div className="admin-billboards-form-row">
                <label className="admin-billboards-title-label">Title</label>
                <input className="admin-billboards-title-input" value={editing.title} onChange={(e) => setEditing({ ...editing, title: e.target.value })} required />
              </div>

              <div className="admin-billboards-form-row">
                <label className="admin-billboards-address-label">Address</label>
                <input className="admin-billboards-address-input" value={editing.address} onChange={(e) => setEditing({ ...editing, address: e.target.value })} required />
              </div>

              <div className="admin-billboards-form-grid admin-billboards-form-grid-2">
                <div>
                  <label className="admin-billboards-latitude-label">Latitude</label>
                  <input className="admin-billboards-latitude-input" type="number" step="0.0000001" value={editing.latitude} onChange={(e) => setEditing({ ...editing, latitude: e.target.value })} required />
                </div>
                <div>
                  <label className="admin-billboards-longitude-label">Longitude</label>
                  <input className="admin-billboards-longitude-input" type="number" step="0.0000001" value={editing.longitude} onChange={(e) => setEditing({ ...editing, longitude: e.target.value })} required />
                </div>
              </div>

              <div className="admin-billboards-form-grid admin-billboards-form-grid-2">
                <div>
                  <label className="admin-billboards-type-label">Type</label>
                  <select className="admin-billboards-type-select" value={editing.type} onChange={(e) => setEditing({ ...editing, type: e.target.value })}>
                    {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                </div>
                <div>
                  <label className="admin-billboards-size-label">Size</label>
                  <input className="admin-billboards-size-input" value={editing.size} onChange={(e) => setEditing({ ...editing, size: e.target.value })} required />
                </div>
              </div>

              <div className="admin-billboards-form-grid admin-billboards-form-grid-3">
                <div>
                  <label className="admin-billboards-pricing-mode-label">Pricing mode</label>
                  <select className="admin-billboards-pricing-mode-select" value={editing.pricing_mode} onChange={(e) => setEditing({ ...editing, pricing_mode: e.target.value })}>
                    <option value="daily">daily</option>
                    <option value="monthly">monthly</option>
                  </select>
                </div>
                <div>
                  <label className="admin-billboards-daily-rate-label">Daily rate (BDT)</label>
                  <input className="admin-billboards-daily-rate-input" type="number" value={editing.daily_rate} onChange={(e) => setEditing({ ...editing, daily_rate: e.target.value })} required />
                </div>
                <div>
                  <label className="admin-billboards-monthly-rate-label">Monthly rate (BDT)</label>
                  <input className="admin-billboards-monthly-rate-input" type="number" value={editing.monthly_rate || ''} onChange={(e) => setEditing({ ...editing, monthly_rate: e.target.value })} />
                </div>
              </div>

              <div className="admin-billboards-form-grid admin-billboards-form-grid-3">
                <div>
                  <label className="admin-billboards-status-label">Status</label>
                  <select className="admin-billboards-status-select" value={editing.status} onChange={(e) => setEditing({ ...editing, status: e.target.value })}>
                    {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                  </select>
                </div>
                <div>
                  <label className="admin-billboards-rating-label">Rating</label>
                  <input className="admin-billboards-rating-input" type="number" min="0" max="5" step="0.1" value={editing.rating} onChange={(e) => setEditing({ ...editing, rating: e.target.value })} />
                </div>
                <div>
                  <label className="admin-billboards-permit-expiry-label">Permit expiry</label>
                  <input className="admin-billboards-permit-expiry-input" type="date" value={editing.permit_expiry_date || ''} onChange={(e) => setEditing({ ...editing, permit_expiry_date: e.target.value })} required />
                </div>
              </div>

              <div className="admin-billboards-form-row">
                <label className="admin-billboards-description-label">Description</label>
                <textarea className="admin-billboards-description-textarea" value={editing.description || ''} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
              </div>

              <div className="admin-billboards-form-actions">
                <button type="submit" className="admin-billboards-save-btn">Save</button>
                <button type="button" className="admin-billboards-cancel-btn" onClick={() => setEditing(null)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {loading ? (
        <p className="admin-billboards-muted">Loading...</p>
      ) : (
        <div className="admin-billboards-table-card">
          <table className="admin-billboards-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Type</th>
                <th>Price</th>
                <th>Status</th>
                <th>Permit expiry</th>
                <th className="admin-billboards-text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {billboards.map((b) => {
                const warning = permitWarning(b.permit_expiry_date);
                return (
                  <tr key={b.id}>
                    <td>
                      <div className="admin-billboards-row-title">{b.title}</div>
                      <div className="admin-billboards-row-sub">{b.address}</div>
                    </td>
                    <td className="admin-billboards-type-cell">{b.type}</td>
                    <td>{b.pricing_mode === 'monthly' ? `${formatBDT(b.monthly_rate)}/mo` : `${formatBDT(b.daily_rate)}/day`}</td>
                    <td><span className="admin-billboards-badge">{b.status}</span></td>
                    <td>
                      {b.permit_expiry_date || 'N/A'}
                      {warning === 'expired' && <span className="admin-billboards-permit-expired">EXPIRED</span>}
                      {warning === 'near-expiry' && <span className="admin-billboards-permit-near-expiry">expiring soon</span>}
                    </td>
                    <td className="admin-billboards-text-right">
                      <div className="admin-billboards-row-actions">
                        <button className="admin-billboards-edit-btn" onClick={() => setEditing({ ...b })}>Edit</button>
                        <button className="admin-billboards-delete-btn" onClick={() => handleDelete(b.id)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </AdminShell>
  );
}
