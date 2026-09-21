import { useEffect, useState } from 'react';
import { AlertTriangle, ShieldAlert, ShieldCheck } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Permits.css';

export default function AdminPermits() {
    usePageTitle('Admin Permits');

  const [data, setData] = useState({ expired: 0, expiring_soon: 0, compliant: 0, billboards: [] });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/admin/permits')
      .then((res) => setData(res.data.data))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <AdminShell title="Permit compliance">
        <p className="admin-permits-muted">Loading...</p>
      </AdminShell>
    );
  }

  const kpis = [
    { slug: 'expired', label: 'Expired', value: data.expired, icon: ShieldAlert },
    { slug: 'expiring-soon', label: 'Expiring in 30 days', value: data.expiring_soon, icon: AlertTriangle },
    { slug: 'compliant', label: 'Compliant', value: data.compliant, icon: ShieldCheck },
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
            {data.billboards.map((b) => (
              <tr key={b.id}>
                <td>
                  <div className="admin-permits-row-title">{b.title}</div>
                  <div className="admin-permits-row-sub">{b.address}</div>
                </td>
                <td>{b.owner?.name ?? 'N/A'}</td>
                <td>{String(b.permit_expiry_date).slice(0, 10)}</td>
                <td className="admin-permits-days-left-cell">
                  {b.days_left < 0 ? `${-b.days_left} days overdue` : `${b.days_left} days`}
                </td>
                <td>
                  {b.days_left < 0 ? (
                    <span className="admin-permits-badge-destructive">Expired</span>
                  ) : b.days_left <= 30 ? (
                    <span className="admin-permits-badge-warning">Expiring soon</span>
                  ) : (
                    <span className="admin-permits-badge-success">Compliant</span>
                  )}
                </td>
              </tr>
            ))}
            {data.billboards.length === 0 && (
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
