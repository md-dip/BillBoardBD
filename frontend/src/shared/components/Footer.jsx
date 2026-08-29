import { Link } from 'react-router-dom';
import './Footer.css';

export default function Footer() {
    return (
        <footer className="site-footer">
            <div className="footer-inner">
                <div className="footer-brand">
                    <div className="footer-brand-name">Billboard<span className="logo-accent">BD</span></div>
                    <p className="footer-tagline">Bangladesh&apos;s simplest way to hire outdoor billboards.</p>
                </div>

                <div className="footer-col">
                    <h4>Platform</h4>
                    <Link to="/billboards">Browse billboards</Link>
                    <Link to="/how-it-works">How it works</Link>
                </div>

                <div className="footer-col">
                    <h4>For owners</h4>
                    <Link to="/register">List your billboard</Link>
                    <Link to="/login">Owner login</Link>
                </div>

                <div className="footer-col">
                    <h4>Contact</h4>
                    <a href="mailto:hello@billboardbd.com">hello@billboardbd.com</a>
                    <span>+880 1XXX-XXXXXX</span>
                </div>
            </div>

            <div className="footer-bottom">© 2026 BillboardBD</div>
        </footer>
    );
}
