import { useEffect, useState } from 'react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Settings.css';

export default function AdminSettings() {
    usePageTitle('Admin Settings');

  const [form, setForm] = useState({ commission_rate: '', advance_percentage: '', final_payment_days: '', listing_fee: '' });
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
        <p className="admin-settings-muted">Loading...</p>
      ) : (
        <div className="admin-settings-form-card">
          <div className="admin-settings-form-card-body">
            <form onSubmit={handleSubmit}>
              <div className="admin-settings-form-row">
                <label className="admin-settings-commission-rate-label" htmlFor="commission">Commission rate (%)</label>
                <input
                  id="commission"
                  className="admin-settings-commission-rate-input"
                  type="number"
                  min="0"
                  max="100"
                  step="0.1"
                  value={form.commission_rate}
                  onChange={(e) => setForm({ ...form, commission_rate: e.target.value })}
                />
              </div>
              <div className="admin-settings-form-row">
                <label className="admin-settings-advance-percentage-label" htmlFor="advance">Advance percentage (%)</label>
                <input
                  id="advance"
                  className="admin-settings-advance-percentage-input"
                  type="number"
                  min="0"
                  max="100"
                  step="0.1"
                  value={form.advance_percentage}
                  onChange={(e) => setForm({ ...form, advance_percentage: e.target.value })}
                />
              </div>
              <div className="admin-settings-form-row">
                <label className="admin-settings-final-payment-days-label" htmlFor="final-payment-days">Final payment window (days)</label>
                <input
                  id="final-payment-days"
                  className="admin-settings-final-payment-days-input"
                  type="number"
                  min="1"
                  max="60"
                  step="1"
                  value={form.final_payment_days}
                  onChange={(e) => setForm({ ...form, final_payment_days: e.target.value })}
                />
                <p className="admin-settings-final-payment-days-help-text">
                  How many days a client has to pay the remaining 70% after the owner accepts a booking.
                </p>
              </div>
              <div className="admin-settings-form-row">
                <label className="admin-settings-listing-fee-label" htmlFor="listing-fee">Board listing fee (BDT)</label>
                <input
                  id="listing-fee"
                  className="admin-settings-listing-fee-input"
                  type="number"
                  min="0"
                  step="1"
                  value={form.listing_fee}
                  onChange={(e) => setForm({ ...form, listing_fee: e.target.value })}
                />
                <p className="admin-settings-listing-fee-help-text">
                  One-time fee an owner pays to list a new board for review.
                </p>
              </div>
              {message && (
                <p className={message.type === 'success' ? 'admin-settings-success-text' : 'admin-settings-error-text'}>
                  {message.text}
                </p>
              )}
              <button type="submit" className="admin-settings-save-settings-btn">Save settings</button>
            </form>
          </div>
        </div>
      )}
    </AdminShell>
  );
}
