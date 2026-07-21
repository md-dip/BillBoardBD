import { useEffect, useState } from 'react';
import api from '../api/axios';

export default function FindBillboards() {
    const [billboards, setBillboards] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/billboards')
            .then((response) => {
                setBillboards(response.data.data);
                setLoading(false);
            })
            .catch((err) => {
                setError(err.message);
                setLoading(false);
            });
    }, []);

    if (loading) return <p className="status">Loading billboards…</p>;
    if (error) return <p className="status error">Error: {error}</p>;

    return (
        <div className="page">
            <h1>Find Billboards</h1>
            <p className="subtitle">{billboards.length} billboards available in Dhaka</p>

            <div className="grid">
                {billboards.map((b) => (
                    <div key={b.id} className="card">
                        <div className={`status-badge ${b.status}`}>{b.status}</div>
                        <h2>{b.title}</h2>
                        <p className="address">📍 {b.address}</p>
                        <p className="description">{b.description}</p>
                        <div className="meta">
                            <span>🏷 {b.type}</span>
                            <span>📐 {b.size}</span>
                            <span>⭐ {b.rating}</span>
                        </div>
                        <div className="price">৳ {Number(b.daily_rate).toLocaleString()} / day</div>
                    </div>
                ))}
            </div>
        </div>
    );
}