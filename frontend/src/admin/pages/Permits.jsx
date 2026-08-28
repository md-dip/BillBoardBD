import { useEffect, useState } from 'react';
import { AlertTriangle, ShieldAlert, ShieldCheck } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import './Permits.css';

function daysUntil(dateStr) {
  return Math.round((new Date(dateStr).getTime() - Date.now()) / 86400000);
}

export default function AdminPermits() {
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
        <p className="admin-permits-muted">Loading...</p>
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

  const kpis = [
    { slug: 'expired', label: 'Expired', value: expired.length, icon: ShieldAlert },
    { slug: 'expiring-soon', label: 'Expiring in 30 days', value: soon.length, icon: AlertTriangle },
    { slug: 'compliant', label: 'Compliant', value: okay.length, icon: ShieldCheck },
  ];

  return (
    <AdminShell title="Permit compliance">
      <div className="admin-permits-kpi-grid">
        {kpis.map((k) => {
          const Icon = k.icon;
          return (
            <div className={`admin-permits-kpi-card-${k.slug}`} key={k.slug}>
              <div className="admin-permits-kpi-header">
                <span className={`admin-permits-kpi-label-${k.slug}`}>{k.label}</span>
                <span className={`admin-permits-kpi-icon-${k.slug}`}>
                  <Icon size={16} />
                </span>
              </div>
              <div className={`admin-permits-kpi-value-${k.slug}`}>{k.value}</div>
            </div>
          );
        })}
      </div>

      <div className="admin-permits-table-card">
        <table className="admin-permits-table">
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
                  <div className="admin-permits-row-title">{b.title}</div>
                  <div className="admin-permits-row-sub">{b.address}</div>
                </td>
                <td>{b.owner?.name ?? 'N/A'}</td>
                <td>{b.permit_expiry_date}</td>
                <td className="admin-permits-days-left-cell">
                  {b.daysLeft < 0 ? `${-b.daysLeft} days overdue` : `${b.daysLeft} days`}
                </td>
                <td>
                  {b.daysLeft < 0 ? (
                    <span className="admin-permits-badge-destructive">Expired</span>
                  ) : b.daysLeft <= 30 ? (
                    <span className="admin-permits-badge-warning">Expiring soon</span>
                  ) : (
                    <span className="admin-permits-badge-success">Compliant</span>
                  )}
                </td>
              </tr>
            ))}
            {withDays.length === 0 && (
              <tr>
                <td colSpan={5} className="admin-permits-table-empty">
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
