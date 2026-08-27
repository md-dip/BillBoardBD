import { useEffect, useState } from 'react';
import { Wallet } from 'lucide-react';
import api from '../../api/axios';
import OwnerShell from '../../components/OwnerShell';
import { formatBDT } from '../../utils/formatPrice';

export default function OwnerPayoutsPage() {
  const [outstanding, setOutstanding] = useState(0);
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/owner/payouts')
      .then((res) => {
        setOutstanding(res.data.data.outstanding);
        setHistory(res.data.data.history);
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <OwnerShell title="Payouts">
        <p className="muted">Loading...</p>
      </OwnerShell>
    );
  }

  return (
    <OwnerShell title="Payouts">
      <div className="kpi-grid">
        <div className="kpi-card">
          <div className="kpi-header">
            <span className="kpi-label">Outstanding balance</span>
            <span className="kpi-icon success"><Wallet size={16} /></span>
          </div>
          <div className="kpi-value">{formatBDT(outstanding)}</div>
        </div>
      </div>

      <p className="muted mb-4">Payouts are typically settled by admin on the 10th of each month.</p>

      <h3 className="section-title">Payout history</h3>
      <div className="card">
        <table className="admin-table">
          <thead>
            <tr>
              <th>Amount</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Paid at</th>
            </tr>
          </thead>
          <tbody>
            {history.map((p) => (
              <tr key={p.id}>
                <td style={{ fontWeight: 600 }}>{formatBDT(p.amount)}</td>
                <td style={{ textTransform: 'capitalize' }}>{p.method || 'N/A'}</td>
                <td>{p.reference || 'N/A'}</td>
                <td>{p.paid_at ? p.paid_at.slice(0, 10) : 'N/A'}</td>
              </tr>
            ))}
            {history.length === 0 && (
              <tr>
                <td colSpan={4} style={{ padding: 32, textAlign: 'center', color: '#64748b' }}>
                  You haven&apos;t received any payouts yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </OwnerShell>
  );
}
