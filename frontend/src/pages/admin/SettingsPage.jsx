import { useEffect, useState } from 'react';
import api from '../../api/axios';
import AdminShell from '../../components/AdminShell';
import './admin.css';

export default function SettingsPage() {
  const [form, setForm] = useState({ commission_rate: '', advance_percentage: '' });
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState(null);

  useEffect(() => {
    api.get('/admin/settings')
      .then((res) => setForm(res.data.data))
      .finally(() => setLoading(false));
  }, []);

  async function handleSubmit(e) {
    e.preventDefault();
    setMessage(null);
    try {
      const res = await api.put('/admin/settings', form);
      setForm(res.data.data);
      setMessage({ type: 'success', text: 'Settings updated.' });
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Could not update settings.' });
    }
  }

  return (
    <AdminShell title="Settings">
      {loading ? (
        <p className="muted">Loading...</p>
      ) : (
        <div className="card" style={{ maxWidth: 400 }}>
          <div className="card-body">
            <form onSubmit={handleSubmit}>
              <div className="form-row">
                <label className="form-label" htmlFor="commission">Commission rate (%)</label>
                <input
                  id="commission"
                  className="form-input"
                  type="number"
                  min="0"
                  max="100"
                  step="0.1"
                  value={form.commission_rate}
                  onChange={(e) => setForm({ ...form, commission_rate: e.target.value })}
                />
              </div>
              <div className="form-row">
                <label className="form-label" htmlFor="advance">Advance percentage (%)</label>
                <input
                  id="advance"
                  className="form-input"
                  type="number"
                  min="0"
                  max="100"
                  step="0.1"
                  value={form.advance_percentage}
                  onChange={(e) => setForm({ ...form, advance_percentage: e.target.value })}
                />
              </div>
              {message && (
                <p className={message.type === 'success' ? 'success-text' : 'error-text'}>
                  {message.text}
                </p>
              )}
              <button type="submit" className="btn btn-primary">Save settings</button>
            </form>
          </div>
        </div>
      )}
    </AdminShell>
  );
}