import { BrowserRouter, Routes, Route, Link, NavLink } from 'react-router-dom';
import Home from './pages/Home';
import HowItWorks from './pages/HowItWorks';
import FindBillboards from './pages/FindBillboards';
import Login from './pages/Login';

export default function App() {
    return (
        <BrowserRouter>
            <Navbar />
            <Routes>
                <Route path="/" element={<Home />} />
                <Route path="/billboards" element={<FindBillboards />} />
                <Route path="/how-it-works" element={<HowItWorks />} />
                <Route path="/login" element={<Login />} />
            </Routes>
        </BrowserRouter>
    );
}

function Navbar() {
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
                <Link to="/login" className="btn-ghost">Log in</Link>
                <button className="btn-primary" onClick={(e) => e.preventDefault()}>Sign up</button>
            </div>
        </nav>
    );
}