import { Link, useLocation } from 'react-router-dom';
import {
  CalendarCheck,
  Home,
  LayoutDashboard,
  LogOut,
  Megaphone,
  Wallet,
} from 'lucide-react';
import { useAuth } from '../../shared/context/AuthContext';
import NotificationBell from '../../shared/components/NotificationBell';
import './OwnerShell.css';

const NAV_ITEMS = [
  { to: '/owner', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/owner/billboards', label: 'My Billboards', icon: Megaphone },
  { to: '/owner/bookings', label: 'Booking Requests', icon: CalendarCheck },
  { to: '/owner/payouts', label: 'Payouts', icon: Wallet },
];

export default function OwnerShell({ children, title }) {
  const { user, logout } = useAuth();
  const { pathname } = useLocation();

  return (
    <div className="owner-shell">
      <aside className="owner-sidebar">
        <Link to="/" className="owner-brand">
          <span className="owner-brand-icon">
            <Megaphone size={16} />
          </span>
          <span>Owner Portal</span>
        </Link>

        <nav className="owner-nav">
          {NAV_ITEMS.map((it) => {
            const active = it.exact ? pathname === it.to : pathname.startsWith(it.to);
            const Icon = it.icon;
            return (
              <Link key={it.to} to={it.to} className={`owner-nav-link ${active ? 'active' : ''}`}>
                <Icon size={16} />
                <span>{it.label}</span>
              </Link>
            );
          })}
        </nav>

        <div className="owner-sidebar-footer">
          <div className="owner-signed-in-label">Signed in as</div>
          <div className="owner-signed-in-name">{user?.name || 'Owner'}</div>
          <div className="owner-footer-actions">
            <Link to="/" className="owner-site-link">
              <Home size={14} /> Site
            </Link>
            <button type="button" className="owner-logout-btn" onClick={logout}>
              <LogOut size={14} />
            </button>
          </div>
        </div>
      </aside>

      <div className="owner-main">
        <header className="owner-header">
          <h1 className="owner-title">{title ?? 'Dashboard'}</h1>
          <div className="owner-header-actions">
            <NotificationBell />
          </div>
        </header>
        <main className="owner-content">{children}</main>
      </div>
    </div>
  );
}
