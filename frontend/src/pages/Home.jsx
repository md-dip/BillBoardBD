import { Link } from 'react-router-dom';

export default function Home() {
    return (
        <div className="page">
            <h1>BillboardBD</h1>
            <p className="subtitle">Find and book billboard advertising space across Dhaka.</p>
            <Link to="/billboards" className="cta">Browse Billboards →</Link>
        </div>
    );
}