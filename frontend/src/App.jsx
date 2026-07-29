import { BrowserRouter, Routes, Route, Link, NavLink } from 'react-router-dom';
import Home from './pages/Home';
import HowItWorks from './pages/HowItWorks';
import FindBillboards from './pages/FindBillboards';
import Login from './pages/Login';
import Register from './pages/Register';
import { useAuth } from './context/AuthContext';
import BillboardDetail from './pages/BillboardDetail';

export default function App() {
    return (
        <BrowserRouter>
            <Navbar />
            <Routes>
                <Route path="/" element={<Home />} />
                <Route path="/billboards" element={<FindBillboards />} />
                <Route path="/how-it-works" element={<HowItWorks />} />
                <Route path="/login" element={<Login />} />
                <Route path="/register" element={<Register />} />
                <Route path="/billboards/:id" element={<BillboardDetail />} />
            </Routes>
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
                <span className="logo-mark">📍</span>
                Billboard<span className="logo-accent">BD</span>
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