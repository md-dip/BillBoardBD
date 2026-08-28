import { Link, NavLink } from 'react-router-dom';
import { LayoutDashboard, LogOut } from 'lucide-react';
import { useAuth } from '../../shared/context/AuthContext';
import './Navbar.css';

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
                <Link to="/admin" className="admin-badge-link">
                    <LayoutDashboard size={14} /> Admin
                </Link>
                <span className="nav-user">{user?.name}</span>
                <button className="nav-icon-btn" onClick={handleLogout} aria-label="Log out" title="Log out">
                    <LogOut size={16} />
                </button>
            </div>
        </nav>
    );
}
