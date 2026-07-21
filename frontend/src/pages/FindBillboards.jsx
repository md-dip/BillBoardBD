import { useEffect, useState } from 'react';
import { MapContainer, TileLayer, Marker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';
import api from '../api/axios';

import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});

const DHAKA_CENTER = [23.8103, 90.4125];
function InvalidateOnMount() {
    const map = useMap();
    useEffect(() => {
        const t = setTimeout(() => map.invalidateSize(), 300);
        const onResize = () => map.invalidateSize();
        window.addEventListener('resize', onResize);
        return () => {
            clearTimeout(t);
            window.removeEventListener('resize', onResize);
        };
    }, [map]);
    return null;
}
// Color palette per billboard type — used for both map markers and card accents.
const TYPE_COLORS = {
    unipole: '#8b5cf6',
    multipole: '#ec4899',
    led: '#22c55e',
    wall: '#f97316',
    backlit: '#3b82f6',
    frontlit: '#06b6d4',
    neon: '#eab308',
    static: '#64748b',
    freestanding: '#14b8a6',
    gantry: '#a855f7',
    rooftop: '#ef4444',
};

// Build a colored div-icon so each marker matches its type color.
function makeIcon(type) {
    const color = TYPE_COLORS[type] || '#0071e3';
    return L.divIcon({
        className: 'billboard-marker',
        html: `<div class="marker-pin" style="background:${color}"></div><div class="marker-stem"></div>`,
        iconSize: [28, 40],
        iconAnchor: [14, 40],
        popupAnchor: [0, -36],
    });
}

function formatPrice(b) {
    if (b.pricing_mode === 'monthly') {
        return `৳${Number(b.monthly_rate).toLocaleString()} / mo`;
    }
    return `৳${Number(b.daily_rate).toLocaleString()} / day`;
}

export default function FindBillboards() {
    const [billboards, setBillboards] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/billboards')
            .then((res) => {
                setBillboards(res.data.data);
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
        <div className="find-page">
            <aside className="sidebar">
                <div className="sidebar-header">
                    <h1>Find billboards</h1>
                    <p className="subtitle">{billboards.length} results</p>

                    <div className="radius-pills">
                        <button className="pill">5 km</button>
                        <button className="pill">10 km</button>
                        <button className="pill">20 km</button>
                        <button className="pill active">All boards</button>
                    </div>

                    <div className="filter-row">
                        <select className="filter-select">
                            <option>All types</option>
                        </select>
                        <input className="filter-input" placeholder="Size e.g. 20×10" />
                    </div>

                    <div className="filter-row">
                        <input className="filter-input" placeholder="Min ৳" />
                        <input className="filter-input" placeholder="Max ৳" />
                    </div>
                </div>

                <div className="list">
                    {billboards.map((b) => (
                        <div key={b.id} className="board-card">
                            <div className="board-photo">No photo</div>
                            <div className="board-info">
                                <div className="board-title-row">
                                    <h2>{b.title}</h2>
                                    <span className={`status-badge ${b.status}`}>{b.status}</span>
                                </div>
                                <p className="address">📍 {b.address}</p>
                                <div className="board-bottom">
                                    <span className="type-tag" style={{color: TYPE_COLORS[b.type] || '#0071e3'}}>
                                        {b.type.toUpperCase()}
                                    </span>
                                    <span className="price">{formatPrice(b)}</span>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </aside>

            <div className="map-wrapper">
                <MapContainer center={DHAKA_CENTER} zoom={12} style={{ height: '100%', width: '100%' }}>
                    <TileLayer
                        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                        url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                    />
                    <InvalidateOnMount />
                    {billboards.map((b) => (
                        <Marker
                            key={b.id}
                            position={[parseFloat(b.latitude), parseFloat(b.longitude)]}
                            icon={makeIcon(b.type)}
                        >
                            <Popup>
                                <strong>{b.title}</strong><br />
                                {b.address}<br />
                                <span style={{color: TYPE_COLORS[b.type], fontWeight: 600}}>
                                    {b.type.toUpperCase()}
                                </span><br />
                                <span style={{color: '#0071e3', fontWeight: 600}}>
                                    {formatPrice(b)}
                                </span>
                            </Popup>
                        </Marker>
                    ))}
                </MapContainer>
            </div>
        </div>
    );
}