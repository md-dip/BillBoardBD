import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './MyBillboards.css';

const TYPES = ['unipole', 'multipole', 'gantry', 'rooftop', 'freestanding', 'static', 'backlit', 'frontlit', 'led', 'neon', 'wall'];
const STATUSES = ['available', 'booked', 'hidden'];

const BLANK_FORM = {
  title: '', description: '', latitude: '', longitude: '', address: '', size: '',
  type: 'unipole', daily_rate: '', monthly_rate: '', pricing_mode: 'daily',
  rating: 0, status: 'available', permit_expiry_date: '',
};

export default function OwnerMyBillboards() {
  const [billboards, setBillboards] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState('');

  function load() {
    setLoading(true);
    api.get('/owner/billboards')
      .then((res) => setBillboards(res.data.data.data))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  async function handleDelete(id) {
    if (!window.confirm('Delete this billboard?')) return;
    await api.delete(`/owner/billboards/${id}`);
    load();
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');
    try {
      if (editing.id) {
        await api.put(`/owner/billboards/${editing.id}`, editing);
      } else {
        await api.post('/owner/billboards', editing);
      }
      setEditing(null);
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not save this billboard.');
    }
  }

  return (
    <OwnerShell title="My Billboards">
      <div className="billboards-section-header">
        <p className="billboards-muted">{billboards.length} billboard{billboards.length === 1 ? '' : 's'} listed</p>
        {!editing && (
          <button type="button" className="billboards-btn billboards-btn-primary" onClick={() => setEditing({ ...BLANK_FORM })}>
            <Plus size={16} /> List new billboard
          </button>
        )}
      </div>

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

              <div className="billboards-form-grid billboards-form-grid-3">
                <div>
                  <label className="billboards-form-label">Status</label>
                  <select className="billboards-form-select" value={editing.status} onChange={(e) => setEditing({ ...editing, status: e.target.value })}>
                    {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                  </select>
                </div>
                <div>
                  <label className="billboards-form-label">Rating</label>
                  <input className="billboards-form-input" type="number" min="0" max="5" step="0.1" value={editing.rating} onChange={(e) => setEditing({ ...editing, rating: e.target.value })} />
                </div>
                <div>
                  <label className="billboards-form-label">Permit expiry</label>
                  <input className="billboards-form-input" type="date" value={editing.permit_expiry_date || ''} onChange={(e) => setEditing({ ...editing, permit_expiry_date: e.target.value })} required />
                </div>
              </div>

              <div className="billboards-form-row">
                <label className="billboards-form-label">Description</label>
                <textarea className="billboards-form-textarea" value={editing.description || ''} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
              </div>

              <div className="billboards-flex billboards-flex-gap-2">
                <button type="submit" className="billboards-btn billboards-btn-primary">Save</button>
                <button type="button" className="billboards-btn billboards-btn-outline" onClick={() => setEditing(null)}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}

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
                <th>Status</th>
                <th>Permit expiry</th>
                <th className="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {billboards.map((b) => (
                <tr key={b.id}>
                  <td>
                    <div className="row-title">{b.title}</div>
                    <div className="row-sub">{b.address}</div>
                  </td>
                  <td className="billboards-type-cell">{b.type}</td>
                  <td>{b.pricing_mode === 'monthly' ? `${formatBDT(b.monthly_rate)}/mo` : `${formatBDT(b.daily_rate)}/day`}</td>
                  <td><span className="billboards-badge billboards-badge-neutral">{b.status}</span></td>
                  <td>{b.permit_expiry_date ? b.permit_expiry_date.slice(0, 10) : 'N/A'}</td>
                  <td className="text-right">
                    <div className="billboards-flex billboards-flex-gap-2 billboards-justify-end">
                      <button className="billboards-btn billboards-btn-outline billboards-btn-sm" onClick={() => setEditing({ ...b })}>Edit</button>
                      <button className="billboards-btn billboards-btn-destructive billboards-btn-sm" onClick={() => handleDelete(b.id)}>Delete</button>
                    </div>
                  </td>
                </tr>
              ))}
              {billboards.length === 0 && (
                <tr>
                  <td colSpan={6} className="billboards-table-empty">
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
