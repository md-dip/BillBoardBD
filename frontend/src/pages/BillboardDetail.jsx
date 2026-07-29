import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api/axios';

export default function BillboardDetail() {
    const { id } = useParams();
    const [billboard, setBillboard] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');

    useEffect(() => {
        setLoading(true);
        setError('');
        api.get(`/billboards/${id}`)
            .then((res) => setBillboard(res.data.data))
            .catch(() => setError('Could not load this billboard.'))
            .finally(() => setLoading(false));
    }, [id]);

    if (loading) return <div className="detail-loading">Loading...</div>;
    if (error) return <div className="detail-loading">{error}</div>;
    if (!billboard) return null;

    // Format price based on pricing mode
    const price = billboard.pricing_mode === 'daily'
        ? `৳${Number(billboard.daily_rate).toLocaleString()} / day`
        : `৳${Number(billboard.monthly_rate).toLocaleString()} / month`;

    // Render stars for the rating (e.g., 4.6 → ★★★★☆)
    const roundedRating = Math.round(Number(billboard.rating) || 0);
    const stars = '★'.repeat(roundedRating) + '☆'.repeat(5 - roundedRating);

    return (
        <div className="detail-wrap">
            <Link to="/billboards" className="detail-back">← Back to search</Link>

            <div>
                <div className="detail-photo">
                    {billboard.photo ? (
                        <img src={billboard.photo} alt={billboard.title} style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: '16px' }} />
                    ) : (
                        'No photo available'
                    )}
                </div>

                <div className="detail-tags">
                    <span className="detail-tag">{billboard.type}</span>
                    <span className="detail-tag">{billboard.size}</span>
                </div>

                <h1 className="detail-title">{billboard.title}</h1>
                <div className="detail-location">📍 {billboard.address}</div>
                <div className="detail-rating">{stars} {billboard.rating}</div>

                <div className="detail-facts">
                    <div className="detail-fact">
                        <div className="detail-fact-label">◈ Size</div>
                        <div className="detail-fact-value">{billboard.size}</div>
                    </div>
                    <div className="detail-fact">
                        <div className="detail-fact-label">⚡ Type</div>
                        <div className="detail-fact-value">{billboard.type}</div>
                    </div>
                    <div className="detail-fact">
                        <div className="detail-fact-label">✓ Status</div>
                        <div className="detail-fact-value">{billboard.status}</div>
                    </div>
                </div>

                <h2 className="detail-section-title">About this billboard</h2>
                <p className="detail-description">
                    {billboard.description || `Prime ${billboard.type} advertising space at ${billboard.address}.`}
                </p>
            </div>

            <aside className="booking-card">
                <div className="booking-price">{price}</div>

                <div className="booking-dates">
                    <div>
                        <label className="auth-label">Start date</label>
                        <input
                            type="date"
                            className="auth-input"
                            value={startDate}
                            onChange={(e) => setStartDate(e.target.value)}
                        />
                    </div>
                    <div>
                        <label className="auth-label">End date</label>
                        <input
                            type="date"
                            className="auth-input"
                            value={endDate}
                            onChange={(e) => setEndDate(e.target.value)}
                        />
                    </div>
                </div>

                <button
                    className="booking-request-btn"
                    onClick={() => alert('Booking flow coming next — for now this is just the detail page.')}
                >
                    Request booking
                </button>

                <p className="booking-note">📅 Pay 30% to confirm, balance before installation</p>
            </aside>
        </div>
    );
}