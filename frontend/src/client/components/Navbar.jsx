import { Link, NavLink } from 'react-router-dom';
import { LogOut } from 'lucide-react';
import { useAuth } from '../../shared/context/AuthContext';
import NotificationBell from '../../shared/components/NotificationBell';
import './Navbar.css';

// The client actor's own navbar. App.jsx only renders this once someone is
// logged in - the logged-out default lives separately in
// shared/components/Navbar.jsx, so this file never has to branch on auth
// state; it can just assume a client user exists.
export default function Navbar() {
    const { user, logout } = useAuth();

    async function handleLogout() {
        await logout();
    }

    return (
        <nav className="navbar">
            <Link to="/" className="logo">
                <span className="logo-mark">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                        <circle cx="12" cy="10" r="3" />
                    </svg>
                </span>
                <span className="logo-text">Billboard<span className="logo-accent">BD</span></span>
            </Link>

            <div className="nav-links">
                <NavLink to="/" end>Home</NavLink>
                <NavLink to="/billboards">Find Billboards</NavLink>
                <NavLink to="/how-it-works">How it works</NavLink>
            </div>

            <div className="nav-actions">
                <Link to="/dashboard" className="my-bookings-btn">My bookings</Link>
                <span className="nav-user">{user?.name.split(' ').slice(0, 2).join(' ')}</span>
                <NotificationBell />
                <button className="nav-icon-btn" onClick={handleLogout} aria-label="Log out" title="Log out">
                    <LogOut size={16} />
                </button>
            </div>
        </nav>
    );
}
