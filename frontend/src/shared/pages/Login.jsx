import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import homePathFor from '../utils/homePathFor';
import usePageTitle from '../hooks/usePageTitle';
import './Login.css';

const DEMO_ACCOUNTS = [
    { label: 'Client', email: 'client@test.com' },
    { label: 'Owner', email: 'owner@test.com' },
    { label: 'Owner 2', email: 'owner2@test.com' },
    { label: 'Admin', email: 'admin@test.com' },
];

export default function Login() {
    usePageTitle('Log in');

    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    // Set by whoever sent us here - the booking card or ProtectedRoute. The
    // notice says why, and `from` is the page to return to after logging in.
    const notice = location.state?.notice;
    const from = location.state?.from?.pathname;
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    function fillDemo(demoEmail) {
        setEmail(demoEmail);
        setPassword('anything');
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setError('');
        setSubmitting(true);
        try {
            const user = await login(email, password);
            // An admin or an owner ALWAYS lands on their own dashboard. `from`
            // is deliberately ignored for them: after an account switch in the
            // same tab it points at the previous actor's page (a client's My
            // Bookings, say), which is exactly how you end up logged in as an
            // owner while looking at client screens. Only a client is returned
            // to what they were doing, so the booking flow can resume.
            const resumeFrom = user.role === 'client' && from;
            navigate(resumeFrom || homePathFor(user.role), { replace: true });
        } catch (err) {
            setError(err.response?.data?.message || 'Login failed');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="login-wrap">
            <div className="login-card">
                <h1 className="login-title">Welcome back</h1>
                <p className="login-subtitle">Log in to book billboards or manage your listings.</p>

                {notice && <p className="login-notice">{notice}</p>}

                <form onSubmit={handleSubmit}>
                    <div className="login-field">
                        <label className="login-label" htmlFor="email">Email</label>
                        <input
                            id="email"
                            type="email"
                            className="login-input"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                    </div>

                    <div className="login-field">
                        <label className="login-label" htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            className="login-input"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </div>

                    {error && <p className="login-error-text">{error}</p>}

                    <button type="submit" className="login-log-in-btn" disabled={submitting}>
                        {submitting ? 'Logging in...' : 'Log in'}
                    </button>

                    <p className="login-forgot-password-link">
                        <Link to="/forgot-password">Forgot password?</Link>
                    </p>

                    <p className="login-footer">
                        No account? <Link to="/register" state={location.state}>Register</Link>
                    </p>

                    <div className="login-demo-box">
                        <div className="login-demo-box-title">Demo accounts</div>
                        {DEMO_ACCOUNTS.map((d) => (
                            <button
                                type="button"
                                key={d.email}
                                className="login-demo-account-btn"
                                onClick={() => fillDemo(d.email)}
                            >
                                {d.label}: {d.email}
                            </button>
                        ))}
                        <div className="login-demo-note">Any password works.</div>
                    </div>
                </form>
            </div>
        </div>
    );
}
