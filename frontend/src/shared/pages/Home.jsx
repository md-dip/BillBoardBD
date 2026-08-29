import { Link } from 'react-router-dom';
import './Home.css';

// Small inline-SVG icons (stroke uses currentColor, so the icon box colour controls them).
// This avoids adding the lucide-react dependency the reference project used.
const icons = {
    map: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
            <circle cx="12" cy="10" r="3" />
        </svg>
    ),
    zap: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
        </svg>
    ),
    shield: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    ),
    trending: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17" />
            <polyline points="16 7 22 7 22 13" />
        </svg>
    ),
    sparkles: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.14a.5.5 0 0 1 .96 0L14.06 8.5A2 2 0 0 0 15.5 9.94l6.14 1.58a.5.5 0 0 1 0 .96L15.5 14.06a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z" />
            <path d="M20 3v4M22 5h-4M4 17v2M5 18H3" />
        </svg>
    ),
    search: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
        </svg>
    ),
};

const features = [
    { icon: icons.map, title: 'Interactive map search', desc: 'Locate billboards by neighborhood, radius, and type — the same way you search for a restaurant.' },
    { icon: icons.zap, title: 'Instant availability', desc: 'Live conflict-checked calendars mean you never double-book a billboard for the same dates.' },
    { icon: icons.shield, title: 'Permit-verified', desc: 'Every listing tracks its City Corporation / RAJUK permit expiry, so your campaign stays legal.' },
    { icon: icons.trending, title: 'Owner analytics', desc: 'Billboard owners see occupancy rate and monthly revenue in a single dashboard.' },
    { icon: icons.sparkles, title: 'Transparent pricing', desc: 'Daily and monthly rates shown upfront. 30% advance to confirm, balance before installation.' },
    { icon: icons.search, title: 'Booking workflow', desc: 'Requests move through admin review, owner acceptance, final payment and installation proof, with notifications at every step.' },
];

export default function Home() {
    return (
        <div className="home-page">
            {/* Hero */}
            <section className="home-hero">
                <div className="home-hero-inner">
                    <span className="home-hero-badge">✦ Bangladesh&apos;s first map-based billboard marketplace</span>
                    <h1 className="home-hero-title">
                        Find the perfect billboard in <span className="home-hero-title-soft">seconds</span>, not weeks.
                    </h1>
                    <p className="home-hero-subtitle">
                        Browse thousands of outdoor advertising spaces on an interactive map. Compare prices, check availability, book online.
                    </p>
                    <div className="home-hero-actions">
                        <Link to="/billboards" className="home-search-billboards-btn">
                            <span className="home-search-billboards-btn-icon">{icons.search}</span> Search billboards
                        </Link>
                        <Link to="/register" className="home-list-your-billboard-btn">List your billboard</Link>
                    </div>
                    <div className="home-hero-stats">
                        <div className="home-hero-stat">
                            <div className="home-hero-stat-n">500+</div>
                            <div className="home-hero-stat-l">billboards</div>
                        </div>
                        <div className="home-hero-stat">
                            <div className="home-hero-stat-n">12</div>
                            <div className="home-hero-stat-l">cities</div>
                        </div>
                        <div className="home-hero-stat">
                            <div className="home-hero-stat-n">99%</div>
                            <div className="home-hero-stat-l">uptime</div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Features */}
            <section className="home-features">
                <div className="home-features-head">
                    <h2 className="home-features-title">Everything you need to run an outdoor campaign</h2>
                    <p className="home-features-sub">From discovery to invoicing, we cover the full billboard workflow.</p>
                </div>
                <div className="home-features-grid">
                    {features.map((f) => (
                        <div className="home-feature-card" key={f.title}>
                            <div className="home-feature-icon">{f.icon}</div>
                            <h3 className="home-feature-title">{f.title}</h3>
                            <p className="home-feature-desc">{f.desc}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* CTA */}
            <section className="home-cta-wrap">
                <div className="home-cta-banner">
                    <div>
                        <h3 className="home-cta-title">Ready to see what&apos;s available near you?</h3>
                        <p className="home-cta-sub">Open the map, filter by type and price, and book in a few clicks.</p>
                    </div>
                    <Link to="/billboards" className="home-open-the-map-btn">Open the map</Link>
                </div>
            </section>
        </div>
    );
}
