import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function Register() {
    const { register } = useAuth();
    const navigate = useNavigate();

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
            // After registration, redirect based on role
            if (user.role === 'owner') {
                navigate('/owner');
            } else {
                navigate('/billboards');
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
        <div className="auth-wrap">
            <div className="auth-card">
                <h1 className="auth-title">Create an account</h1>
                <p className="auth-subtitle">Book billboards or list your own space for hire.</p>

                <form onSubmit={handleSubmit}>
                    <div className="auth-field">
                        <label className="auth-label">I want to</label>
                        <div className="role-toggle">
                            <button
                                type="button"
                                className={`role-option ${form.role === 'client' ? 'active' : ''}`}
                                onClick={() => selectRole('client')}
                            >
                                <div className="role-option-title">Book billboards</div>
                                <div className="role-option-desc">Advertiser / brand</div>
                            </button>
                            <button
                                type="button"
                                className={`role-option ${form.role === 'owner' ? 'active' : ''}`}
                                onClick={() => selectRole('owner')}
                            >
                                <div className="role-option-title">List my billboards</div>
                                <div className="role-option-desc">Billboard owner</div>
                            </button>
                        </div>
                    </div>

                    <div className="auth-field">
                        <label className="auth-label" htmlFor="name">
                            {form.role === 'owner' ? 'Company name' : 'Full name / Company'}
                        </label>
                        <input
                            id="name"
                            name="name"
                            className="auth-input"
                            value={form.name}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="auth-field">
                        <label className="auth-label" htmlFor="email">Email</label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            className="auth-input"
                            value={form.email}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="auth-field">
                        <label className="auth-label" htmlFor="password">Password</label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            className="auth-input"
                            value={form.password}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="auth-field">
                        <label className="auth-label" htmlFor="phone">Phone (optional)</label>
                        <input
                            id="phone"
                            name="phone"
                            className="auth-input"
                            value={form.phone}
                            onChange={handleChange}
                        />
                    </div>

                    {Object.values(errors).flat().map((msg, i) => (
                        <p className="auth-error" key={i}>{msg}</p>
                    ))}

                    <button type="submit" className="auth-submit" disabled={submitting}>
                        {submitting ? 'Creating account...' : 'Register'}
                    </button>

                    <p className="auth-footer">
                        Already have an account? <Link to="/login">Log in</Link>
                    </p>
                </form>
            </div>
        </div>
    );
}