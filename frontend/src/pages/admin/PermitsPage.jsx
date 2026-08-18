import { useEffect, useState } from 'react';
import { AlertTriangle, ShieldAlert, ShieldCheck } from 'lucide-react';
import api from '../../api/axios';
import AdminShell from '../../components/AdminShell';
import './admin.css';

function daysUntil(dateStr) {
  return Math.round((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

export default function PermitsPage() {
  const [billboards, setBillboards] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/admin/billboards')
      .then((res) => setBillboards(res.data.data.data))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <AdminShell title="Permit compliance">
        <p className="muted">Loading...</p>
      </AdminShell>
    );
  }

  const withDays = billboards
    .filter((b) => b.permit_expiry_date)
    .map((b) => ({ ...b, daysLeft: daysUntil(b.permit_expiry_date) }))
    .sort((a, b) => a.daysLeft - b.daysLeft);

  const expired = withDays.filter((b) => b.daysLeft < 0);
  const soon = withDays.filter((b) => b.daysLeft >= 0 && b.daysLeft <= 30);
  const okay = withDays.filter((b) => b.daysLeft > 30);

  return (
    <AdminShell title="Permit compliance">
      <div className="kpi-grid">
        <SummaryCard icon={ShieldAlert} accent="destructive" label="Expired" value={expired.length} />
        <SummaryCard icon={AlertTriangle} accent="warning" label="Expiring in 30 days" value={soon.length} />
        <SummaryCard icon={ShieldCheck} accent="success" label="Compliant" value={okay.length} />
      </div>

      <div className="card mt-6">
        <table className="admin-table">
          <thead>
            <tr>
              <th>Billboard</th>
              <th>Owner</th>
              <th>Permit expiry</th>
              <th>Days left</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {withDays.map((b) => (
              <tr key={b.id}>
                <td>
                  <div className="row-title">{b.title}</div>
                  <div className="row-sub">{b.address}</div>
                </td>
                <td>{b.owner?.name ?? 'N/A'}</td>
                <td>{b.permit_expiry_date}</td>
                <td style={{ fontWeight: 600 }}>
                  {b.daysLeft < 0 ? `${-b.daysLeft} days overdue` : `${b.daysLeft} days`}
                </td>
                <td>
                  {b.daysLeft < 0 ? (
                    <span className="badge badge-destructive">Expired</span>
                  ) : b.daysLeft <= 30 ? (
                    <span className="badge badge-warning">Expiring soon</span>
                  ) : (
                    <span className="badge badge-success">Compliant</span>
                  )}
                </td>
              </tr>
            ))}
            {withDays.length === 0 && (
              <tr>
                <td colSpan={5} style={{ padding: 32, textAlign: 'center', color: '#64748b' }}>
                  No permit data.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </AdminShell>
  );
}

function SummaryCard({ icon: Icon, label, value, accent }) {
  return (
    <div className="kpi-card">
      <div className="kpi-header">
        <span className="kpi-label">{label}</span>
        <span className={`kpi-icon ${accent}`}>
          <Icon size={16} />
        </span>
      </div>
      <div className="kpi-value">{value}</div>
    </div>
  );
}