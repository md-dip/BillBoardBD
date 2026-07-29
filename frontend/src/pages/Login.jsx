import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const DEMO_ACCOUNTS = [
    { label: 'Client', email: 'client@test.com' },
    { label: 'Owner', email: 'owner@test.com' },
    { label: 'Admin', email: 'admin@test.com' },
];

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
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
            // Redirect based on role
            if (user.role === 'admin') {
                navigate('/');  // we don't have /admin yet — go home for now
            } else {
                navigate('/billboards');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Login failed');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="auth-wrap">
            <div className="auth-card">
                <h1 className="auth-title">Welcome back</h1>
                <p className="auth-subtitle">Log in to book billboards or manage your listings.</p>

                <form onSubmit={handleSubmit}>
                    <div className="auth-field">
                        <label className="auth-label" htmlFor="email">Email</label>
                        <input
                            id="email"
                            type="email"
                            className="auth-input"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required
                        />
                    </div>

                    <div className="auth-field">
                        <label className="auth-label" htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            className="auth-input"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </div>

                    {error && <p className="auth-error">{error}</p>}

                    <button type="submit" className="auth-submit" disabled={submitting}>
                        {submitting ? 'Logging in...' : 'Log in'}
                    </button>

                    <p className="auth-footer">
                        No account? <Link to="/register">Register</Link>
                    </p>

                    <div className="demo-box">
                        <div className="demo-box-title">Demo accounts</div>
                        {DEMO_ACCOUNTS.map((d) => (
                            <button
                                type="button"
                                key={d.email}
                                className="demo-account"
                                onClick={() => fillDemo(d.email)}
                            >
                                {d.label}: {d.email}
                            </button>
                        ))}
                        <div className="demo-note">Any password works.</div>
                    </div>
                </form>
            </div>
        </div>
    );
}