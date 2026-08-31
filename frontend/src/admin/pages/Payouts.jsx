import { useEffect, useState } from 'react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Payouts.css';

const METHODS = ['bkash', 'nagad', 'bank'];

export default function AdminPayouts() {
  const [outstanding, setOutstanding] = useState([]);
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);
  const [payingOwnerId, setPayingOwnerId] = useState(null);
  const [method, setMethod] = useState('bank');
  const [reference, setReference] = useState('');
  const [error, setError] = useState('');

  function load() {
    setLoading(true);
    api.get('/admin/payouts')
      .then((res) => {
        setOutstanding(res.data.data.outstanding);
        setHistory(res.data.data.history);
      })
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  async function handlePayout(ownerId) {
    setError('');
    try {
      await api.post(`/admin/payouts/${ownerId}`, { method, reference });
      setPayingOwnerId(null);
      setReference('');
      load();
    } catch (err) {
      setError(err.response?.data?.message || 'Could not record this payout.');
    }
  }

  return (
    <AdminShell title="Payouts">
      <p className="admin-payouts-intro-text">Payouts are typically settled on the 10th of each month. Amounts below are computed live from settled bookings not yet paid out.</p>

      {error && <p className="admin-payouts-error-text">{error}</p>}

      {loading ? (
        <p className="admin-payouts-muted">Loading...</p>
      ) : (
        <>
          <h3 className="admin-payouts-section-title">Outstanding balances</h3>
          <div className="admin-payouts-outstanding-table-card">
            <table className="admin-payouts-outstanding-table">
              <thead>
                <tr>
                  <th>Owner</th>
                  <th>Amount owed</th>
                  <th className="admin-payouts-text-right">Action</th>
                </tr>
              </thead>
              <tbody>
                {outstanding.map((row) => (
                  <tr key={row.owner.id}>
                    <td>
                      <div className="admin-payouts-row-title">{row.owner.name}</div>
                      <div className="admin-payouts-row-sub">{row.owner.email}</div>
                    </td>
                    <td className="admin-payouts-amount-cell">{formatBDT(row.amount)}</td>
                    <td className="admin-payouts-text-right">
                      {payingOwnerId === row.owner.id ? (
                        <div className="admin-payouts-inline-form">
                          <select className="admin-payouts-method-select" value={method} onChange={(e) => setMethod(e.target.value)}>
                            {METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                          </select>
                          <input
                            className="admin-payouts-reference-input"
                            placeholder="Reference (optional)"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                          />
                          <button className="admin-payouts-confirm-btn" onClick={() => handlePayout(row.owner.id)}>Confirm</button>
                          <button className="admin-payouts-cancel-btn" onClick={() => setPayingOwnerId(null)}>Cancel</button>
                        </div>
                      ) : (
                        <button className="admin-payouts-pay-out-btn" onClick={() => { setPayingOwnerId(row.owner.id); setReference(''); }}>
                          Pay out
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {outstanding.length === 0 && (
                  <tr>
                    <td colSpan={3} className="admin-payouts-outstanding-table-empty">
                      No owners currently have an outstanding balance.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <h3 className="admin-payouts-section-title">Payout history</h3>
          <div className="admin-payouts-history-table-card">
            <table className="admin-payouts-history-table">
              <thead>
                <tr>
                  <th>Owner</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                  <th>Paid at</th>
                </tr>
              </thead>
              <tbody>
                {history.map((p) => (
                  <tr key={p.id}>
                    <td>{p.owner?.name}</td>
                    <td>{formatBDT(p.amount)}</td>
                    <td className="admin-payouts-method-cell">{p.method || 'N/A'}</td>
                    <td>{p.reference || 'N/A'}</td>
                    <td>{p.paid_at ? p.paid_at.slice(0, 10) : 'N/A'}</td>
                  </tr>
                ))}
                {history.length === 0 && (
                  <tr>
                    <td colSpan={5} className="admin-payouts-history-table-empty">
                      No payouts recorded yet.
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
