import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronUp, Image as ImageIcon } from 'lucide-react';
import api from '../../shared/api/axios';
import { formatBDT } from '../../shared/utils/formatPrice';
import './MyBookings.css';

const METHODS = ['bkash', 'nagad', 'bank'];

const STATUS_LABEL = {
    pending_payment: 'Payment due',
    pending_admin_review: 'Under review',
    pending_owner_approval: 'Awaiting owner',
    confirmed: 'Confirmed',
    paid_in_full: 'Paid in full',
    pending_proof_review: 'Verifying installation',
    active: 'Live',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

function paymentSummary(booking) {
    const advance = booking.payments?.find((p) => p.payment_type === 'advance');
    const balance = booking.payments?.find((p) => p.payment_type === 'balance');

    if (balance?.status === 'paid') return { text: 'Fully paid', payable: null };
    if (balance?.status === 'pending') return { text: 'Balance due', payable: balance };
    if (advance?.status === 'refunded') return { text: 'Advance refunded', payable: null };
    if (advance?.status === 'paid') return { text: 'Advance paid', payable: null };
    if (advance?.status === 'pending') return { text: 'Advance unpaid', payable: advance };
    return { text: 'No payment yet', payable: null };
}

export default function Dashboard() {
    const [bookings, setBookings] = useState([]);
    const [loading, setLoading] = useState(true);
    const [expandedId, setExpandedId] = useState(null);
    const [payingId, setPayingId] = useState(null);
    const [method, setMethod] = useState('bkash');
    const [txnRef, setTxnRef] = useState('');
    const [payError, setPayError] = useState('');

    function load() {
        setLoading(true);
        api.get('/bookings/my')
            .then((res) => setBookings(res.data.data))
            .finally(() => setLoading(false));
    }

    useEffect(load, []);

    async function handlePay(paymentId) {
        setPayError('');
        if (!txnRef) { setPayError('Enter a transaction reference.'); return; }
        try {
            await api.post(`/payments/${paymentId}/pay`, { method, transaction_ref: txnRef });
            setPayingId(null);
            setTxnRef('');
            load();
        } catch (err) {
            setPayError(err.response?.data?.message || 'Payment failed.');
        }
    }

    return (
        <div className="page mybookings-page">
            <div className="mybookings-header">
                <h1>My bookings</h1>
                <Link to="/billboards" className="book-another-btn">Book another</Link>
            </div>

            {loading && <p className="subtitle">Loading your bookings...</p>}

            {!loading && bookings.length === 0 && (
                <div className="mybookings-empty">
                    You haven&apos;t requested any bookings yet. <Link to="/billboards">Browse billboards</Link>.
                </div>
            )}

            <div className="mybookings-list">
                {bookings.map((b) => {
                    const expanded = expandedId === b.id;
                    const { text: paymentText, payable } = paymentSummary(b);
                    const isPaying = payingId === b.id;
                    const refund = b.payments?.find((p) => p.payment_type === 'refund');

                    return (
                        <div className="mybookings-card" key={b.id}>
                            <div className="mybookings-row">
                                {b.billboard?.photo ? (
                                    <img className="mybookings-thumb" src={b.billboard.photo} alt="" />
                                ) : (
                                    <div className="mybookings-thumb mybookings-thumb-empty">
                                        <ImageIcon size={20} />
                                    </div>
                                )}

                                <div className="mybookings-info">
                                    <div className="mybookings-title">{b.billboard?.title}</div>
                                    <div className="mybookings-dates">
                                        {b.start_date?.slice(0, 10)} &rarr; {b.end_date?.slice(0, 10)}
                                    </div>
                                </div>

                                <div className="mybookings-amount-col">
                                    <div className="mybookings-amount">{formatBDT(b.total_amount)}</div>
                                    {payable ? (
                                        <button
                                            type="button"
                                            className="mybookings-pay-link"
                                            onClick={() => {
                                                setPayingId(isPaying ? null : b.id);
                                                setPayError('');
                                                setTxnRef('');
                                            }}
                                        >
                                            {paymentText}
                                            {b.status === 'confirmed' && b.final_payment_due_at
                                                ? ` by ${b.final_payment_due_at.slice(0, 10)}`
                                                : ''}
                                            {' '}&middot; Pay now
                                        </button>
                                    ) : (
                                        <div className="mybookings-payment-text">{paymentText}</div>
                                    )}
                                </div>

                                <span className={`mybookings-badge mybookings-badge-${b.status}`}>
                                    {STATUS_LABEL[b.status] || b.status}
                                </span>

                                <div className="mybookings-actions">
                                    <button
                                        type="button"
                                        className="view-btn"
                                        onClick={() => setExpandedId(expanded ? null : b.id)}
                                    >
                                        {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />} View
                                    </button>
                                    {b.invoices?.length > 0 && (
                                        <Link to={`/bookings/${b.id}/invoice`} className="invoice-btn">
                                            Invoice
                                        </Link>
                                    )}
                                </div>
                            </div>

                            {isPaying && payable && (
                                <div className="mybookings-pay-form">
                                    <select className="pay-method-select" value={method} onChange={(e) => setMethod(e.target.value)}>
                                        {METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
                                    </select>
                                    <input
                                        className="pay-ref-input"
                                        placeholder="Transaction ref"
                                        value={txnRef}
                                        onChange={(e) => setTxnRef(e.target.value)}
                                    />
                                    <button
                                        type="button"
                                        className="confirm-btn"
                                        style={{ width: 'auto' }}
                                        onClick={() => handlePay(payable.id)}
                                    >
                                        Confirm {formatBDT(payable.amount)}
                                    </button>
                                    <button type="button" className="cancel-btn" onClick={() => setPayingId(null)}>
                                        Cancel
                                    </button>
                                    {payError && <p className="booking-error">{payError}</p>}
                                </div>
                            )}

                            {expanded && (
                                <div className="mybookings-detail">
                                    {b.creative_url && (
                                        <img className="mybookings-creative" src={b.creative_url} alt="Ad creative" />
                                    )}
                                    <div className="mybookings-detail-grid">
                                        <div>
                                            <span className="mybookings-detail-label">Brand</span>
                                            <div>{b.brand_name || 'N/A'}</div>
                                        </div>
                                        <div>
                                            <span className="mybookings-detail-label">Category</span>
                                            <div>{b.ad_category || 'N/A'}</div>
                                        </div>
                                        <div>
                                            <span className="mybookings-detail-label">Address</span>
                                            <div>{b.billboard?.address || 'N/A'}</div>
                                        </div>
                                        <div className="mybookings-detail-span">
                                            <span className="mybookings-detail-label">Campaign description</span>
                                            <div>{b.campaign_description || 'N/A'}</div>
                                        </div>
                                    </div>
                                    {b.status === 'rejected' && b.rejection_reason && (
                                        <div className="mybookings-rejection">Rejected: {b.rejection_reason}</div>
                                    )}
                                    {refund && (
                                        <div className="mybookings-refund">
                                            Advance of {formatBDT(refund.amount)} refunded to your {refund.method} account
                                            {refund.refunded_at ? ` on ${refund.refunded_at.slice(0, 10)}` : ''}
                                            {refund.transaction_ref ? ` · ref ${refund.transaction_ref}` : ''}
                                        </div>
                                    )}
                                    {b.proof_of_postings?.some((p) => p.status === 'verified') && (
                                        <div className="mybookings-proof">
                                            <span className="mybookings-detail-label">Installation proof</span>
                                            <div className="mybookings-proof-gallery">
                                                {b.proof_of_postings
                                                    .filter((p) => p.status === 'verified')
                                                    .map((p) => (
                                                        <img key={p.id} src={p.photo_url} alt="Proof of posting" className="mybookings-proof-photo" />
                                                    ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
