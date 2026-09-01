import { useState, useEffect } from 'react';
import api from '../../shared/api/axios';
import { daysBetweenInclusive, todayIso } from '../../shared/utils/dateRange';
import { formatBDT } from '../../shared/utils/formatPrice';
import './BookingWizard.css';

export default function BookingWizard({ billboard, advancePercentage, holdMinutes = 15 }) {
    const [step, setStep] = useState('dates'); // 'dates' | 'campaign' | 'review' | 'done'
    const [startDate, setStartDate] = useState(todayIso());
    const [endDate, setEndDate] = useState(todayIso());
    const [booking, setBooking] = useState(null);
    const [payment, setPayment] = useState(null);

    // Step 2 fields
    const [brandName, setBrandName] = useState('');
    const [adCategory, setAdCategory] = useState('');
    const [campaignDescription, setCampaignDescription] = useState('');
    const [creative, setCreative] = useState(null);

    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [secondsLeft, setSecondsLeft] = useState(null);

    const days = daysBetweenInclusive(startDate, endDate);
    const rate = billboard.pricing_mode === 'monthly'
        ? Number(billboard.monthly_rate) / 30
        : Number(billboard.daily_rate);
    const previewTotal = Math.round(rate * days);
    const previewAdvance = Math.round(previewTotal * (advancePercentage / 100));

    // Countdown timer - runs while a hold is active (steps 2 & 3).
    useEffect(() => {
        if (!booking?.expires_at) return;
        const tick = () => {
            const diff = Math.floor((new Date(booking.expires_at) - new Date()) / 1000);
            setSecondsLeft(diff > 0 ? diff : 0);
        };
        tick();
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }, [booking]);

    const mmss = secondsLeft != null
        ? `${String(Math.floor(secondsLeft / 60)).padStart(2, '0')}:${String(secondsLeft % 60).padStart(2, '0')}`
        : null;

    async function handleHold() {
        setLoading(true); setError('');
        try {
            const res = await api.post('/bookings/hold', {
                billboard_id: billboard.id,
                start_date: startDate,
                end_date: endDate,
            });
            setBooking(res.data.data);
            setStep('campaign');
        } catch (err) {
            setError(err.response?.data?.message || 'Could not hold these dates.');
        } finally { setLoading(false); }
    }

    async function handleCampaign() {
        if (!creative) { setError('Please attach an ad creative image.'); return; }
        setLoading(true); setError('');
        try {
            const form = new FormData();
            form.append('brand_name', brandName);
            form.append('ad_category', adCategory);
            form.append('campaign_description', campaignDescription);
            form.append('creative', creative);
            const res = await api.post(`/bookings/${booking.id}/campaign`, form);
            setBooking(res.data.data);
            setPayment(res.data.data.payments?.[0] || null);
            setStep('review');
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save campaign details.');
        } finally { setLoading(false); }
    }

    async function handleCheckout() {
        setLoading(true); setError('');
        try {
            const res = await api.post(`/payments/${payment.id}/checkout`);
            // Leave the SPA for the SSLCommerz hosted page. The browser comes
            // back to /dashboard?payment=<result> once payment finishes.
            window.location.assign(res.data.data.gateway_url);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not start checkout.');
            setLoading(false);
        }
    }

    // Values shown after the hold exists come from the SERVER booking, not preview.
    const total = booking ? booking.total_amount : previewTotal;
    const advance = booking ? booking.advance_amount : previewAdvance;

    return (
        <div className="booking-card">
            {mmss && (step === 'campaign' || step === 'review') && (
                <div className="booking-timer">⏱ {mmss}</div>
            )}

            <div className="booking-price">
                {formatBDT(billboard.pricing_mode === 'monthly' ? billboard.monthly_rate : billboard.daily_rate)}
                <small> / {billboard.pricing_mode === 'monthly' ? 'month' : 'day'}</small>
            </div>

            {step !== 'done' && (
                <div className="booking-steps">
                    <span className={`booking-step ${step === 'dates' ? 'active' : ''}`}>1. Dates</span>
                    <span className={`booking-step ${step === 'campaign' ? 'active' : ''}`}>→ 2. Campaign</span>
                    <span className={`booking-step ${step === 'review' ? 'active' : ''}`}>→ 3. Review &amp; pay</span>
                </div>
            )}

            {/* STEP 1 - DATES */}
            {step === 'dates' && (
                <>
                    <div className="booking-dates">
                        <div>
                            <label className="auth-label">Start date</label>
                            <input className="auth-input" type="date" min={todayIso()} value={startDate}
                                onChange={(e) => { setStartDate(e.target.value); if (e.target.value > endDate) setEndDate(e.target.value); }} />
                        </div>
                        <div>
                            <label className="auth-label">End date</label>
                            <input className="auth-input" type="date" min={startDate} value={endDate}
                                onChange={(e) => setEndDate(e.target.value)} />
                        </div>
                    </div>

                    <div className="booking-summary">
                        <div className="booking-summary-row"><span>{days} day(s)</span></div>
                        <div className="booking-summary-row"><span>Advance ({advancePercentage}%)</span><span>{formatBDT(previewAdvance)}</span></div>
                        <div className="booking-summary-row booking-summary-total"><span>Total</span><span>{formatBDT(previewTotal)}</span></div>
                    </div>

                    <button className="hold-these-dates-btn" onClick={handleHold} disabled={loading}>
                        {loading ? 'Holding...' : 'Hold these dates'}
                    </button>
                    <p className="booking-note">🔒 Dates are locked to you for {holdMinutes} minutes while you finish your request.</p>
                </>
            )}

            {/* STEP 2 - CAMPAIGN */}
            {step === 'campaign' && (
                <>
                    <p className="booking-range">{startDate} → {endDate}</p>
                    <label className="auth-label">Brand name</label>
                    <input className="auth-input" value={brandName} onChange={(e) => setBrandName(e.target.value)} />

                    <label className="auth-label">Ad category</label>
                    <input className="auth-input" value={adCategory} onChange={(e) => setAdCategory(e.target.value)} />

                    <label className="auth-label">Campaign description</label>
                    <textarea className="auth-input" rows={3} value={campaignDescription} onChange={(e) => setCampaignDescription(e.target.value)} />

                    <label className="auth-label">Ad creative</label>
                    <input className="auth-input" type="file" accept="image/*" onChange={(e) => setCreative(e.target.files[0] || null)} />
                    {creative && <img className="booking-preview" src={URL.createObjectURL(creative)} alt="preview" />}

                    <button className="continue-to-review-btn" onClick={handleCampaign} disabled={loading}>
                        {loading ? 'Saving...' : 'Continue to review'}
                    </button>
                </>
            )}

            {/* STEP 3 - REVIEW & PAY */}
            {step === 'review' && (
                <>
                    <div className="booking-summary">
                        <div className="booking-summary-row"><span>Dates</span><span>{startDate} → {endDate}</span></div>
                        <div className="booking-summary-row"><span>Brand</span><span>{brandName}</span></div>
                        <div className="booking-summary-row"><span>Category</span><span>{adCategory}</span></div>
                        <div className="booking-summary-row"><span>Advance due now</span><span>{formatBDT(advance)}</span></div>
                        <div className="booking-summary-row booking-summary-total"><span>Total</span><span>{formatBDT(total)}</span></div>
                    </div>

                    <button className="pay-advance-btn" onClick={handleCheckout} disabled={loading}>
                        {loading ? 'Redirecting…' : `Pay advance ${formatBDT(advance)}`}
                    </button>
                    <p className="booking-note">🔒 You pay securely on SSLCommerz (card, bKash, Nagad, Rocket). The {advancePercentage}% advance submits your request for admin review; the balance is due before installation.</p>
                </>
            )}

            {/* DONE */}
            {step === 'done' && (
                <div className="booking-success">
                    <div className="booking-success-check">✓</div>
                    <h3>Booking request submitted</h3>
                    <p>Your advance payment is confirmed. We'll notify you once admin reviews your request (usually within 24 hours).</p>
                    <a className="go-to-my-bookings-btn" href="/dashboard">Go to My Bookings</a>
                </div>
            )}

            {error && <p className="booking-error">{error}</p>}
        </div>
    );
}