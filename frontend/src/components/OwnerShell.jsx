import { Link, useLocation } from 'react-router-dom';
import {
  CalendarCheck,
  Home,
  LayoutDashboard,
  LogOut,
  Megaphone,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import '../pages/admin/admin.css';

const NAV_ITEMS = [
  { to: '/owner', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/owner/billboards', label: 'My Billboards', icon: Megaphone },
  { to: '/owner/bookings', label: 'Booking Requests', icon: CalendarCheck },
];

export default function OwnerShell({ children, title }) {
  const { user, logout } = useAuth();
  const { pathname } = useLocation();

  return (
    <div className="admin-shell">
      <aside className="admin-sidebar">
        <Link to="/" className="admin-brand">
          <span className="admin-brand-icon">
            <Megaphone size={16} />
          </span>
          <span>Owner Portal</span>
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
          <div className="admin-signed-in-name">{user?.name || 'Owner'}</div>
          <div className="admin-footer-actions">
            <Link to="/" className="btn btn-outline btn-sm" style={{ flex: 1 }}>
              <Home size={14} /> Site
            </Link>
            <button type="button" className="btn btn-outline btn-sm btn-icon" onClick={logout}>
              <LogOut size={14} />
            </button>
          </div>
        </div>
      </aside>

      <div className="admin-main">
        <header className="admin-header">
          <h1 className="admin-title">{title ?? 'Dashboard'}</h1>
        </header>
        <main className="admin-content">{children}</main>
      </div>
    </div>
  );
}
