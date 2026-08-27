import { useEffect, useState } from 'react';
import api from '../../shared/api/axios';
import AdminShell from '../../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';

const METHODS = ['bkash', 'nagad', 'bank', 'cash'];

export default function AdminPayoutsPage() {
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
      <p className="muted mb-4">Payouts are typically settled on the 10th of each month. Amounts below are computed live from settled bookings not yet paid out.</p>

      {error && <p className="error-text">{error}</p>}

      {loading ? (
        <p className="muted">Loading...</p>
      ) : (
        <>
          <h3 className="section-title">Outstanding balances</h3>
          <div className="card mb-6">
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Owner</th>
                  <th>Amount owed</th>
                  <th className="text-right">Action</th>
                </tr>
              </thead>
              <tbody>
                {outstanding.map((row) => (
                  <tr key={row.owner.id}>
                    <td>
                      <div className="row-title">{row.owner.name}</div>
                      <div className="row-sub">{row.owner.email}</div>
                    </td>
                    <td style={{ fontWeight: 600 }}>{formatBDT(row.amount)}</td>
                    <td className="text-right">
                      {payingOwnerId === row.owner.id ? (
                        <div className="flex flex-gap-2 justify-end items-center">
                          <select className="form-select" style={{ width: 110 }} value={method} onChange={(e) => setMethod(e.target.value)}>
                            {METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                          </select>
                          <input
                            className="form-input"
                            style={{ width: 140 }}
                            placeholder="Reference (optional)"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                          />
                          <button className="btn btn-primary btn-sm" onClick={() => handlePayout(row.owner.id)}>Confirm</button>
                          <button className="btn btn-outline btn-sm" onClick={() => setPayingOwnerId(null)}>Cancel</button>
                        </div>
                      ) : (
                        <button className="btn btn-primary btn-sm" onClick={() => { setPayingOwnerId(row.owner.id); setReference(''); }}>
                          Pay out
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {outstanding.length === 0 && (
                  <tr>
                    <td colSpan={3} style={{ padding: 32, textAlign: 'center', color: '#64748b' }}>
                      No owners currently have an outstanding balance.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <h3 className="section-title">Payout history</h3>
          <div className="card">
            <table className="admin-table">
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
                    <td style={{ textTransform: 'capitalize' }}>{p.method || 'N/A'}</td>
                    <td>{p.reference || 'N/A'}</td>
                    <td>{p.paid_at ? p.paid_at.slice(0, 10) : 'N/A'}</td>
                  </tr>
                ))}
                {history.length === 0 && (
                  <tr>
                    <td colSpan={5} style={{ padding: 32, textAlign: 'center', color: '#64748b' }}>
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
