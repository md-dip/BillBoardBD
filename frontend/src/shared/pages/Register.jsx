import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import usePageTitle from '../hooks/usePageTitle';
import './Register.css';

export default function Register() {
    usePageTitle('Register');

    const { register } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    // Carried over from the login page when a visitor was sent to sign in
    // mid-task (see shared/pages/Login.jsx) - same meaning as there.
    const notice = location.state?.notice;
    const from = location.state?.from?.pathname;

    const [form, setForm] = useState({
        name: '',
        email: '',
        password: '',
        phone: '',
        role: 'client',  // default = advertiser
    });
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    function handleChange(e) {
        setForm({ ...form, [e.target.name]: e.target.value });
    }

    function selectRole(role) {
        setForm({ ...form, role });
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setErrors({});
        setSubmitting(true);
        try {
            const user = await register(form);
            // After registration, redirect based on role - a brand new client
            // goes back to whatever they were trying to book, if anything.
            if (user.role === 'owner') {
                navigate('/owner');
            } else {
                navigate(from || '/billboards');
            }
        } catch (err) {
            // Laravel returns { errors: { email: ['already taken'], ... } } for 422 validation
            if (err.response?.data?.errors) {
                setErrors(err.response.data.errors);
            } else {
                setErrors({ general: [err.response?.data?.message || 'Registration failed'] });
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="register-wrap">
            <div className="register-card">
                <h1 className="register-title">Create an account</h1>
                <p className="register-subtitle">Book billboards or list your own space for hire.</p>

                {notice && <p className="register-notice">{notice}</p>}

                <form onSubmit={handleSubmit}>
                    <div className="register-field">
                        <label className="register-label">I want to</label>
                        <div className="register-role-toggle">
                            <button
                                type="button"
                                className={`register-role-book-billboards-btn ${form.role === 'client' ? 'active' : ''}`}
                                onClick={() => selectRole('client')}
                            >
                                <div className="register-role-book-billboards-title">Book billboards</div>
                                <div className="register-role-book-billboards-desc">Advertiser / brand</div>
                            </button>
                            <button
                                type="button"
                                className={`register-role-list-my-billboards-btn ${form.role === 'owner' ? 'active' : ''}`}
                                onClick={() => selectRole('owner')}
                            >
                                <div className="register-role-list-my-billboards-title">List my billboards</div>
                                <div className="register-role-list-my-billboards-desc">Billboard owner</div>
                            </button>
                        </div>
                    </div>

                    <div className="register-field">
                        <label className="register-label" htmlFor="name">
                            {form.role === 'owner' ? 'Company name' : 'Full name / Company'}
                        </label>
                        <input
                            id="name"
                            name="name"
                            className="register-input"
                            value={form.name}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="register-field">
                        <label className="register-label" htmlFor="email">Email</label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            className="register-input"
                            value={form.email}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="register-field">
                        <label className="register-label" htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            className="register-input"
                            value={form.password}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="register-field">
                        <label className="register-label" htmlFor="phone">Phone (optional)</label>
                        <input
                            id="phone"
                            name="phone"
                            className="register-input"
                            value={form.phone}
                            onChange={handleChange}
                        />
                    </div>

                    {Object.values(errors).flat().map((msg, i) => (
                        <p className="register-error-text" key={i}>{msg}</p>
                    ))}

                    <button type="submit" className="register-register-btn" disabled={submitting}>
                        {submitting ? 'Creating account...' : 'Register'}
                    </button>

                    <p className="register-footer">
                        Already have an account? <Link to="/login" state={location.state}>Log in</Link>
                    </p>
                </form>
            </div>
        </div>
    );
}
