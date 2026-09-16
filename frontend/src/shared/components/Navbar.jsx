import { useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { Menu, X } from 'lucide-react';
import './Navbar.css';

// The default navbar shown to anonymous (logged-out) visitors. Once someone
// logs in, App.jsx swaps this out for that actor's own navbar instead (e.g.
// client/components/Navbar.jsx for the client role) - this file stays purely
// the logged-out case, it doesn't need to know about any actor.
export default function Navbar() {
    const [open, setOpen] = useState(false);

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

            <button
                type="button"
                className="navbar-toggle"
                aria-label={open ? 'Close menu' : 'Open menu'}
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
            >
                {open ? <X size={22} /> : <Menu size={22} />}
            </button>

            <div className={`navbar-menu${open ? ' navbar-menu-open' : ''}`} onClick={() => setOpen(false)}>
                <div className="nav-links">
                    <NavLink to="/" end>Home</NavLink>
                    <NavLink to="/billboards">Find Billboards</NavLink>
                    <NavLink to="/how-it-works">How it works</NavLink>
                </div>

                <div className="nav-actions">
                    <Link to="/login" className="log-in-link">Log in</Link>
                    <Link to="/register" className="sign-up-btn">Sign up</Link>
                </div>
            </div>
        </nav>
    );
}
