import { BrowserRouter, Routes, Route, Link } from 'react-router-dom';
import FindBillboards from './pages/FindBillboards';

export default function App() {
    return (
        <BrowserRouter>
            <nav className="navbar">
                <Link to="/" className="logo">BillboardBD</Link>
                <Link to="/billboards">Find Billboards</Link>
            </nav>

            <Routes>
                <Route path="/" element={<Home />} />
                <Route path="/billboards" element={<FindBillboards />} />
            </Routes>
        </BrowserRouter>
    );
}

function Home() {
    return (
        <div className="page">
            <h1>BillboardBD</h1>
            <p>Book premium billboard advertising spaces across Dhaka.</p>
            <Link to="/billboards" className="cta">Browse Billboards →</Link>
        </div>
    );
}