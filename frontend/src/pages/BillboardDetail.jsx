import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api/axios';
import BookingWizard from '../components/BookingWizard';

export default function BillboardDetail() {
    const { id } = useParams();
    const [billboard, setBillboard] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    // Booking rules from the DB, so the wizard shows the real advance % / hold time.
    const [settings, setSettings] = useState({ advance_percentage: 30, hold_minutes: 15 });

    useEffect(() => {
        setLoading(true);
        setError('');
        api.get(`/billboards/${id}`)
            .then((res) => setBillboard(res.data.data))
            .catch(() => setError('Could not load this billboard.'))
            .finally(() => setLoading(false));
    }, [id]);

    useEffect(() => {
        api.get('/settings/public')
            .then((res) => setSettings(res.data.data))
            .catch(() => { /* keep the safe defaults if this fails */ });
    }, []);

    if (loading) return <div className="detail-loading">Loading...</div>;
    if (error) return <div className="detail-loading">{error}</div>;
    if (!billboard) return null;

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

            <aside>
                <BookingWizard
                    billboard={billboard}
                    advancePercentage={settings.advance_percentage}
                    holdMinutes={settings.hold_minutes}
                />
            </aside>
        </div>
    );
}