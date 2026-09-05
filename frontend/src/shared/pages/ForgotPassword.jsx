import { useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import usePageTitle from '../hooks/usePageTitle';
import './ForgotPassword.css';

export default function ForgotPassword() {
    usePageTitle('Forgot password');

    const [email, setEmail] = useState('');
    const [error, setError] = useState('');
    const [sent, setSent] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e) {
        e.preventDefault();
        setError('');
        setSubmitting(true);
        try {
            const res = await api.post('/forgot-password', { email });
            // The API answers the same way for a registered and an unknown
            // address on purpose (see Shared/AuthController@forgotPassword),
            // so there is nothing to branch on here - just show what it said.
            setSent(res.data.message);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not send the reset link. Try again.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="forgot-password-wrap">
            <div className="forgot-password-card">
                <h1 className="forgot-password-title">Forgot your password?</h1>
                <p className="forgot-password-subtitle">
                    Enter the email you signed up with and we will send you a link to set a new password.
                </p>

                {sent ? (
                    <>
                        <p className="forgot-password-sent-notice">{sent}</p>
                        <p className="forgot-password-footer">
                            <Link to="/login">Back to log in</Link>
                        </p>
                    </>
                ) : (
                    <form onSubmit={handleSubmit}>
                        <div className="forgot-password-field">
                            <label className="forgot-password-label" htmlFor="email">Email</label>
                            <input
                                id="email"
                                type="email"
                                className="forgot-password-input"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                required
                            />
                        </div>

                        {error && <p className="forgot-password-error-text">{error}</p>}

                        <button type="submit" className="forgot-password-send-reset-link-btn" disabled={submitting}>
                            {submitting ? 'Sending...' : 'Send reset link'}
                        </button>

                        <p className="forgot-password-footer">
                            Remembered it? <Link to="/login">Log in</Link>
                        </p>
                    </form>
                )}
            </div>
        </div>
    );
}
