import { Link, useLocation } from 'react-router-dom';
import {
  BarChart3,
  CalendarCheck,
  Home,
  LayoutDashboard,
  LogOut,
  Megaphone,
  Settings,
  ShieldAlert,
  Wallet,
} from 'lucide-react';
import { useAuth } from '../../shared/context/AuthContext';
import NotificationBell from '../../shared/components/NotificationBell';
import './AdminShell.css';

const NAV_ITEMS = [
  { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/admin/billboards', label: 'Billboards', icon: Megaphone },
  { to: '/admin/bookings', label: 'Bookings', icon: CalendarCheck },
  { to: '/admin/permits', label: 'Permits', icon: ShieldAlert },
  { to: '/admin/payouts', label: 'Payouts', icon: Wallet },
  { to: '/admin/reports', label: 'Reports', icon: BarChart3 },
  { to: '/admin/settings', label: 'Settings', icon: Settings },
];

export default function AdminShell({ children, title }) {
  const { user, logout } = useAuth();
  const { pathname } = useLocation();

  return (
    <div className="admin-shell">
      <aside className="admin-sidebar">
        <Link to="/" className="admin-brand">
          <span className="admin-brand-icon">
            <Megaphone size={16} />
          </span>
          <span>BillboardBD</span>
        </Link>

        <nav className="admin-nav">
          {NAV_ITEMS.map((it) => {
            const active = it.exact ? pathname === it.to : pathname.startsWith(it.to);
            const Icon = it.icon;
            return (
              <Link key={it.to} to={it.to} className={`admin-nav-link ${active ? 'active' : ''}`}>
                <Icon size={16} />
                <span>{it.label}</span>
              </Link>
            );
          })}
        </nav>

        <div className="admin-sidebar-footer">
          <div className="admin-signed-in-label">Signed in as</div>
          <div className="admin-signed-in-name">{user?.name || 'Admin'}</div>
          <div className="admin-footer-actions">
            <Link to="/" className="admin-site-link">
              <Home size={14} /> Site
            </Link>
            <button type="button" className="admin-logout-btn" onClick={logout}>
              <LogOut size={14} />
            </button>
          </div>
        </div>
      </aside>

      <div className="admin-main">
        <header className="admin-header">
          <h1 className="admin-title">{title ?? 'Admin'}</h1>
          <div className="admin-header-actions">
            <NotificationBell />
          </div>
        </header>
        <main className="admin-content">{children}</main>
      </div>
    </div>
  );
}
