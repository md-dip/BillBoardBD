import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import './Login.css';

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
                navigate('/admin');
            } else if (user.role === 'owner') {
                navigate('/owner');
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
        <div className="login-wrap">
            <div className="login-card">
                <h1 className="login-title">Welcome back</h1>
                <p className="login-subtitle">Log in to book billboards or manage your listings.</p>

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

                    <p className="login-footer">
                        No account? <Link to="/register">Register</Link>
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
