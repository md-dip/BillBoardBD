import { useEffect, useState } from 'react';
import { Wallet } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Payouts.css';

export default function OwnerPayouts() {
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
        <p className="payouts-muted">Loading...</p>
      </OwnerShell>
    );
  }

  return (
    <OwnerShell title="Payouts">
      <div className="payouts-kpi-grid">
        <div className="payouts-kpi-card">
          <div className="payouts-kpi-header">
            <span className="payouts-kpi-label">Outstanding balance</span>
            <span className="payouts-kpi-icon success"><Wallet size={16} /></span>
          </div>
          <div className="payouts-kpi-value">{formatBDT(outstanding)}</div>
        </div>
      </div>

      <p className="payouts-muted payouts-mb-4">Payouts are typically settled by admin on the 10th of each month.</p>

      <h3 className="payouts-section-title">Payout history</h3>
      <div className="payouts-card">
        <table className="payouts-table">
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
                <td className="payouts-amount-cell">{formatBDT(p.amount)}</td>
                <td className="payouts-method-cell">{p.method || 'N/A'}</td>
                <td>{p.reference || 'N/A'}</td>
                <td>{p.paid_at ? p.paid_at.slice(0, 10) : 'N/A'}</td>
              </tr>
            ))}
            {history.length === 0 && (
              <tr>
                <td colSpan={4} className="payouts-table-empty">
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
