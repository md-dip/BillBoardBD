import { BrowserRouter, Routes, Route, Link, NavLink, useLocation } from 'react-router-dom';
import Home from './pages/Home';
import HowItWorks from './pages/HowItWorks';
import FindBillboards from './pages/FindBillboards';
import Login from './pages/Login';
import Register from './pages/Register';
import { useAuth } from './context/AuthContext';
import BillboardDetail from './pages/BillboardDetail';
import ProtectedRoute from './components/ProtectedRoute';
import AdminDashboard from './pages/admin/Dashboard';
import AdminBillboards from './pages/admin/BillboardsPage';
import AdminBookings from './pages/admin/BookingsPage';
import AdminPermits from './pages/admin/PermitsPage';
import AdminReports from './pages/admin/ReportsPage';
import AdminSettings from './pages/admin/SettingsPage';

function AppRoutes() {
    const { pathname } = useLocation();
    const isAdmin = pathname.startsWith('/admin');

    return (
        <>
            {!isAdmin && <Navbar />}
            <Routes>
                <Route path="/" element={<Home />} />
                <Route path="/billboards" element={<FindBillboards />} />
                <Route path="/how-it-works" element={<HowItWorks />} />
                <Route path="/login" element={<Login />} />
                <Route path="/register" element={<Register />} />
                <Route path="/billboards/:id" element={<BillboardDetail />} />

                <Route path="/admin" element={<ProtectedRoute requireRole="admin"><AdminDashboard /></ProtectedRoute>} />
                <Route path="/admin/billboards" element={<ProtectedRoute requireRole="admin"><AdminBillboards /></ProtectedRoute>} />
                <Route path="/admin/bookings" element={<ProtectedRoute requireRole="admin"><AdminBookings /></ProtectedRoute>} />
                <Route path="/admin/permits" element={<ProtectedRoute requireRole="admin"><AdminPermits /></ProtectedRoute>} />
                <Route path="/admin/reports" element={<ProtectedRoute requireRole="admin"><AdminReports /></ProtectedRoute>} />
                <Route path="/admin/settings" element={<ProtectedRoute requireRole="admin"><AdminSettings /></ProtectedRoute>} />
            </Routes>
            {!isAdmin && <Footer />}
        </>
    );
}

export default function App() {
    return (
        <BrowserRouter>
            <AppRoutes />
        </BrowserRouter>
    );
}

function Navbar() {
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
                {user ? (
                    <>
                        <span className="nav-user">Hi, {user.name.split(' ')[0]}</span>
                        <button className="btn-ghost" onClick={handleLogout}>Log out</button>
                    </>
                ) : (
                    <>
                        <Link to="/login" className="btn-ghost">Log in</Link>
                        <Link to="/register" className="btn-primary">Sign up</Link>
                    </>
                )}
            </div>
        </nav>
    );
}

function Footer() {
    return (
        <footer className="site-footer">
            <div className="footer-inner">
                <div className="footer-brand">
                    <div className="footer-brand-name">Billboard<span className="logo-accent">BD</span></div>
                    <p className="footer-tagline">Bangladesh&apos;s simplest way to hire outdoor billboards.</p>
                </div>

                <div className="footer-col">
                    <h4>Platform</h4>
                    <Link to="/billboards">Browse billboards</Link>
                    <Link to="/how-it-works">How it works</Link>
                </div>

                <div className="footer-col">
                    <h4>For owners</h4>
                    <Link to="/register">List your billboard</Link>
                    <Link to="/login">Owner login</Link>
                </div>

                <div className="footer-col">
                    <h4>Contact</h4>
                    <a href="mailto:hello@billboardbd.com">hello@billboardbd.com</a>
                    <span>+880 1XXX-XXXXXX</span>
                </div>
            </div>

            <div className="footer-bottom">© 2026 BillboardBD</div>
        </footer>
    );
}