import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../api/axios';
import usePageTitle from '../hooks/usePageTitle';
import './ResetPassword.css';

export default function ResetPassword() {
    usePageTitle('Reset password');

    const navigate = useNavigate();

    // Both come from the emailed link, built by the createUrlUsing() callback
    // in AppServiceProvider. They are passed straight back to the API, which
    // is what proves the person holding them owns the mailbox.
    const [searchParams] = useSearchParams();
    const token = searchParams.get('token') || '';
    const email = searchParams.get('email') || '';

    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e) {
        e.preventDefault();
        setErrors({});
        setSubmitting(true);
        try {
            await api.post('/reset-password', {
                token,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            // The reset also deleted every API token for this account, so there
            // is no session to step into - send them to log in with the new
            // password, carrying the reason so the login page can explain why.
            navigate('/login', {
                replace: true,
                state: { notice: 'Your password has been reset. Log in with your new password.' },
            });
        } catch (err) {
            if (err.response?.data?.errors) {
                setErrors(err.response.data.errors);
            } else {
                setErrors({ general: [err.response?.data?.message || 'Could not reset your password.'] });
            }
        } finally {
            setSubmitting(false);
        }
    }

    // Someone who typed the URL by hand, or followed a link that lost its query
    // string, has nothing to submit - say so instead of failing on send.
    if (!token || !email) {
        return (
            <div className="reset-password-wrap">
                <div className="reset-password-card">
                    <h1 className="reset-password-title">This link is incomplete</h1>
                    <p className="reset-password-subtitle">
                        Open the reset link straight from the email, or request a new one.
                    </p>
                    <p className="reset-password-footer">
                        <Link to="/forgot-password">Request a new link</Link>
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="reset-password-wrap">
            <div className="reset-password-card">
                <h1 className="reset-password-title">Set a new password</h1>
                <p className="reset-password-subtitle">Choose a new password for {email}.</p>

                <form onSubmit={handleSubmit}>
                    <div className="reset-password-field">
                        <label className="reset-password-label" htmlFor="password">New password</label>
                        <input
                            id="password"
                            type="password"
                            className="reset-password-input"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </div>

                    <div className="reset-password-field">
                        <label className="reset-password-label" htmlFor="password_confirmation">
                            Confirm new password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            className="reset-password-input"
                            value={passwordConfirmation}
                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                            required
                        />
                    </div>

                    {Object.values(errors).flat().map((msg, i) => (
                        <p className="reset-password-error-text" key={i}>{msg}</p>
                    ))}

                    <button type="submit" className="reset-password-set-new-password-btn" disabled={submitting}>
                        {submitting ? 'Saving...' : 'Set new password'}
                    </button>

                    <p className="reset-password-footer">
                        <Link to="/login">Back to log in</Link>
                    </p>
                </form>
            </div>
        </div>
    );
}
