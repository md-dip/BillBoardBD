import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import './NotFound.css';

// Catch-all page for any URL that matches no route at all (see the "*" route
// at the bottom of App.jsx). It lives in shared/ because all three actors can
// land here - a mistyped /admin/... or /owner/... URL falls through to this
// same page - so "Go home" points at whichever home the visitor actually has.
export default function NotFound() {
    const { user } = useAuth();

    let homePath = '/';
    if (user?.role === 'admin') homePath = '/admin';
    else if (user?.role === 'owner') homePath = '/owner';

    return (
        <div className="not-found-page">
            <p className="not-found-code">404</p>
            <h1 className="not-found-title">Page not found</h1>
            <p className="not-found-message">
                The page you&apos;re looking for doesn&apos;t exist or has been moved.
            </p>
            <Link to={homePath} className="not-found-go-home-btn">Go home</Link>
        </div>
    );
}
