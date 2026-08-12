import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { MapContainer, TileLayer, Marker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';
import api from '../api/axios';
import BookingWizard from '../components/BookingWizard';

import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

// Same default-marker fix your map page uses, so the pin shows even if the
// user opens this detail page directly (without visiting the map first).
L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});

// A map placed in a below-the-fold container often renders grey because
// Leaflet measured the container before it had its final size. Calling
// invalidateSize() after mount forces it to re-measure and paint correctly.
function InvalidateOnMount() {
    const map = useMap();
    useEffect(() => {
        const t = setTimeout(() => map.invalidateSize(), 0);
        return () => clearTimeout(t);
    }, [map]);
    return null;
}

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

    const roundedRating = Math.round(Number(billboard.rating) || 0);
    const stars = '★'.repeat(roundedRating) + '☆'.repeat(5 - roundedRating);

    const lat = Number(billboard.latitude);
    const lng = Number(billboard.longitude);
    const hasCoords = !Number.isNaN(lat) && !Number.isNaN(lng);
    const bookedRanges = billboard.booked_ranges || [];

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

                <h2 className="detail-section-title">Availability</h2>
                {bookedRanges.length > 0 ? (
                    <ul className="detail-booked">
                        {bookedRanges.map((r, i) => (
                            <li key={i}>{r.start_date} to {r.end_date} <span className="detail-booked-tag">(booked)</span></li>
                        ))}
                    </ul>
                ) : (
                    <p className="detail-description">No bookings yet — every date is open.</p>
                )}

                {hasCoords && (
                    <>
                        <h2 className="detail-section-title">Location</h2>
                        <div className="detail-map">
                            <MapContainer center={[lat, lng]} zoom={15} scrollWheelZoom={false} style={{ height: '100%', width: '100%' }}>
                                <TileLayer
                                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                                />
                                <Marker position={[lat, lng]}>
                                    <Popup>{billboard.title}</Popup>
                                </Marker>
                                <InvalidateOnMount />
                            </MapContainer>
                        </div>
                    </>
                )}
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