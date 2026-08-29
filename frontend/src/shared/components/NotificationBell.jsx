import { useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import api from '../api/axios';
import './NotificationBell.css';

// Genuinely shared: rendered inside the client Navbar, AdminShell's header,
// and OwnerShell's header alike. Fully self-contained (own CSS import right
// here), so none of those three consumers need to style it themselves.
function timeAgo(dateStr) {
  const diffMs = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

export default function NotificationBell() {
  const [notifications, setNotifications] = useState([]);
  const [open, setOpen] = useState(false);
  const wrapRef = useRef(null);

  function load() {
    api.get('/notifications').then((res) => setNotifications(res.data.data));
  }

  useEffect(() => {
    load();
    const interval = setInterval(load, 30000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    function handleClickOutside(e) {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  async function markAllRead() {
    await api.patch('/notifications/read-all');
    load();
  }

  const unreadCount = notifications.filter((n) => !n.read_at).length;

  return (
    <div className="notification-bell-wrap" ref={wrapRef}>
      <button
        type="button"
        className="notification-bell-toggle-btn"
        onClick={() => setOpen((v) => !v)}
        aria-label="Notifications"
      >
        <Bell size={18} />
        {unreadCount > 0 && <span className="notification-bell-badge">{unreadCount > 9 ? '9+' : unreadCount}</span>}
      </button>

      {open && (
        <div className="notification-bell-dropdown">
          <div className="notification-bell-dropdown-header">
            <span>Notifications</span>
            {unreadCount > 0 && (
              <button type="button" className="notification-bell-mark-all-read-btn" onClick={markAllRead}>
                Mark all read
              </button>
            )}
          </div>
          <div className="notification-bell-list">
            {notifications.length === 0 && <p className="notification-bell-empty">No notifications yet.</p>}
            {notifications.map((n) => (
              <div key={n.id} className={`notification-bell-item ${!n.read_at ? 'unread' : ''}`}>
                <div className="notification-bell-item-title">{n.data.title}</div>
                <div className="notification-bell-item-body">{n.data.body}</div>
                <div className="notification-bell-item-time">{timeAgo(n.created_at)}</div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
