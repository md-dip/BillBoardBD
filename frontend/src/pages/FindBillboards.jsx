import { useEffect, useState } from 'react';
import { MapContainer, TileLayer, Marker, Popup, Circle, useMap } from 'react-leaflet';
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

// Recenter the map when the user location is known.
function FlyToUser({ userPos }) {
    const map = useMap();
    useEffect(() => {
        if (userPos) {
            map.flyTo(userPos, 13, { duration: 1.2 });
        }
    }, [userPos, map]);
    return null;
}

const TYPE_COLORS = {
    unipole: '#8b5cf6', multipole: '#ec4899', led: '#22c55e',
    wall: '#f97316', backlit: '#3b82f6', frontlit: '#06b6d4',
    neon: '#eab308', static: '#64748b', freestanding: '#14b8a6',
    gantry: '#a855f7', rooftop: '#ef4444',
};

function makeIcon(type) {
    const color = TYPE_COLORS[type] || '#0071e3';
    const svg = `
        <svg width="36" height="44" viewBox="0 0 36 44" xmlns="http://www.w3.org/2000/svg">
            <rect x="2" y="1" width="32" height="22" rx="4" fill="${color}" stroke="#ffffff" stroke-width="2"/>
            <line x1="7" y1="9" x2="29" y2="9" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.9"/>
            <line x1="7" y1="14" x2="29" y2="14" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.9"/>
            <line x1="7" y1="19" x2="21" y2="19" stroke="#ffffff" stroke-width="2" stroke-linecap="round" opacity="0.9"/>
            <rect x="16.5" y="23" width="3" height="15" fill="#1d1d1f"/>
            <polygon points="13,38 23,38 19,44 17,44" fill="#1d1d1f"/>
        </svg>`;
    return L.divIcon({
        className: 'billboard-marker',
        html: svg,
        iconSize: [36, 44],
        iconAnchor: [18, 44],
        popupAnchor: [0, -40],
    });
}

// Special "you are here" marker
const userIcon = L.divIcon({
    className: 'user-marker',
    html: `<div class="user-dot"></div><div class="user-pulse"></div>`,
    iconSize: [24, 24],
    iconAnchor: [12, 12],
});

// Pull just the numbers out of a size string ("20ft x 10ft", "20*10", "20×10", "20")
// so matching doesn't care what separator or units were used.
function normalizeSize(s) {
    const nums = String(s).match(/\d+(\.\d+)?/g);
    return nums ? nums.join('x') : String(s).toLowerCase().replace(/\s+/g, '');
}

function effectivePrice(b) {
    return b.pricing_mode === 'monthly' ? Number(b.monthly_rate) : Number(b.daily_rate);
}

function formatPrice(b) {
    if (b.pricing_mode === 'monthly') {
        return `৳${Number(b.monthly_rate).toLocaleString()} / mo`;
    }
    return `৳${Number(b.daily_rate).toLocaleString()} / day`;
}

const RADII = [5, 10, 20];

export default function FindBillboards() {
    const [billboards, setBillboards] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [radius, setRadius] = useState(null);     // null = "All boards"
    const [userPos, setUserPos] = useState(null);
    const [geoError, setGeoError] = useState(null);
    const [typeFilter, setTypeFilter] = useState('');
    const [sizeFilter, setSizeFilter] = useState('');
    const [minPrice, setMinPrice] = useState('');
    const [maxPrice, setMaxPrice] = useState('');

    // On mount, ask for user location once
    useEffect(() => {
        if (!navigator.geolocation) {
            setGeoError('Geolocation not supported by your browser');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => setUserPos([pos.coords.latitude, pos.coords.longitude]),
            (err) => setGeoError(err.message),
            { enableHighAccuracy: true, timeout: 10000 }
        );
    }, []);

    // Fetch boards whenever radius or user position changes
    useEffect(() => {
        setLoading(true);
        setError(null);

        // If no radius chosen OR no user location, fetch all boards
        if (radius === null || !userPos) {
            api.get('/billboards')
                .then((res) => {
                    setBillboards(res.data.data);
                    setLoading(false);
                })
                .catch((err) => {
                    setError(err.message);
                    setLoading(false);
                });
            return;
        }

        // Otherwise, fetch nearby
        api.get('/billboards/nearby', {
            params: { lat: userPos[0], lng: userPos[1], radius },
        })
            .then((res) => {
                setBillboards(res.data.data);
                setLoading(false);
            })
            .catch((err) => {
                setError(err.message);
                setLoading(false);
            });
    }, [radius, userPos]);

    const handleRadiusClick = (r) => {
        if (r !== null && !userPos) {
            alert('Please allow location access to filter by radius');
            return;
        }
        setRadius(r);
    };

    const filteredBillboards = billboards.filter((b) => {
        if (typeFilter && b.type !== typeFilter) return false;
        if (sizeFilter.trim() && !normalizeSize(b.size).includes(normalizeSize(sizeFilter))) return false;
        const price = effectivePrice(b);
        if (minPrice !== '' && price < Number(minPrice)) return false;
        if (maxPrice !== '' && price > Number(maxPrice)) return false;
        return true;
    });

    return (
        <div className="find-page">
            <aside className="sidebar">
                <div className="sidebar-header">
                    <h1>Find billboards</h1>
                    <p className="subtitle">
                        {loading ? 'Loading…' : `${filteredBillboards.length} results`}
                        {radius && userPos && ` within ${radius} km`}
                    </p>

                    {geoError && (
                        <p className="geo-warning">📍 Location unavailable showing all boards</p>
                    )}

                    <div className="radius-pills">
                        {RADII.map((r) => (
                            <button
                                key={r}
                                className={`pill ${radius === r ? 'active' : ''}`}
                                onClick={() => handleRadiusClick(r)}
                            >
                                {r} km
                            </button>
                        ))}
                        <button
                            className={`pill ${radius === null ? 'active' : ''}`}
                            onClick={() => handleRadiusClick(null)}
                        >
                            All boards
                        </button>
                    </div>

                    <div className="filter-row">
                        <select
                            className="filter-select"
                            value={typeFilter}
                            onChange={(e) => setTypeFilter(e.target.value)}
                        >
                            <option value="">All types</option>
                            {Object.keys(TYPE_COLORS).sort().map((t) => (
                                <option key={t} value={t}>{t.toUpperCase()}</option>
                            ))}
                        </select>
                        <input
                            className="filter-input"
                            placeholder="Size e.g. 20×10"
                            value={sizeFilter}
                            onChange={(e) => setSizeFilter(e.target.value)}
                        />
                    </div>

                    <div className="filter-row">
                        <input
                            className="filter-input"
                            placeholder="Min ৳"
                            value={minPrice}
                            onChange={(e) => setMinPrice(e.target.value)}
                        />
                        <input
                            className="filter-input"
                            placeholder="Max ৳"
                            value={maxPrice}
                            onChange={(e) => setMaxPrice(e.target.value)}
                        />
                    </div>
                </div>

                <div className="list">
                    {error && <p className="status error">Error: {error}</p>}
                    {!loading && !error && filteredBillboards.length === 0 && (
                        <p className="status">No billboards match your filters.</p>
                    )}
                    {filteredBillboards.map((b) => (
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
                                    <span className="price">
                                        {formatPrice(b)}
                                        {b.distance_km !== undefined && (
                                            <span className="distance"> · {Number(b.distance_km).toFixed(1)} km</span>
                                        )}
                                    </span>
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
                    <FlyToUser userPos={userPos} />

                    {userPos && (
                        <>
                            <Marker position={userPos} icon={userIcon}>
                                <Popup>You are here</Popup>
                            </Marker>
                            {radius && (
                                <Circle
                                    center={userPos}
                                    radius={radius * 1000}
                                    pathOptions={{ color: '#0071e3', fillOpacity: 0.08, weight: 2 }}
                                />
                            )}
                        </>
                    )}

                    {filteredBillboards.map((b) => (
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
                                {b.distance_km !== undefined && (
                                    <><br /><em>{Number(b.distance_km).toFixed(2)} km away</em></>
                                )}
                            </Popup>
                        </Marker>
                    ))}
                </MapContainer>
            </div>
        </div>
    );
}