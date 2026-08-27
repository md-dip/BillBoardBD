import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronUp, Image as ImageIcon, Printer, X } from 'lucide-react';
import api from '../api/axios';
import { formatBDT } from '../utils/formatPrice';

const METHODS = ['bkash', 'nagad', 'bank', 'cash'];

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
    const [invoiceBooking, setInvoiceBooking] = useState(null);
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
                <Link to="/billboards" className="btn-primary">Book another</Link>
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
                                        className="pay-btn-outline"
                                        onClick={() => setExpandedId(expanded ? null : b.id)}
                                    >
                                        {expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />} View
                                    </button>
                                    <button type="button" className="pay-btn-outline" onClick={() => setInvoiceBooking(b)}>
                                        Invoice
                                    </button>
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
                                        className="booking-request-btn"
                                        style={{ width: 'auto' }}
                                        onClick={() => handlePay(payable.id)}
                                    >
                                        Confirm {formatBDT(payable.amount)}
                                    </button>
                                    <button type="button" className="pay-btn-outline" onClick={() => setPayingId(null)}>
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

            {invoiceBooking && (
                <InvoiceModal booking={invoiceBooking} onClose={() => setInvoiceBooking(null)} />
            )}
        </div>
    );
}

function InvoiceModal({ booking, onClose }) {
    return (
        <div className="mb-invoice-overlay" onClick={onClose}>
            <div className="mb-invoice-modal" onClick={(e) => e.stopPropagation()}>
                <div className="mb-invoice-header">
                    <h3>Invoice &middot; Booking #{booking.id}</h3>
                    <button type="button" className="mb-invoice-close" onClick={onClose}>
                        <X size={18} />
                    </button>
                </div>

                <div className="mb-invoice-body">
                    <div className="mb-invoice-row"><span>Billboard</span><span>{booking.billboard?.title}</span></div>
                    <div className="mb-invoice-row"><span>Address</span><span>{booking.billboard?.address}</span></div>
                    <div className="mb-invoice-row">
                        <span>Dates</span>
                        <span>{booking.start_date?.slice(0, 10)} &rarr; {booking.end_date?.slice(0, 10)}</span>
                    </div>
                    <div className="mb-invoice-row"><span>Booked on</span><span>{booking.created_at?.slice(0, 10)}</span></div>
                    <div className="mb-invoice-row">
                        <span>Status</span>
                        <span style={{ textTransform: 'capitalize' }}>{booking.status.replace('_', ' ')}</span>
                    </div>

                    <table className="mb-invoice-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th className="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {booking.payments?.map((p) => (
                                <tr key={p.id}>
                                    <td style={{ textTransform: 'capitalize' }}>{p.payment_type}</td>
                                    <td style={{ textTransform: 'capitalize' }}>{p.method || 'N/A'}</td>
                                    <td style={{ textTransform: 'capitalize' }}>{p.status}</td>
                                    <td className="num">{formatBDT(p.amount)}</td>
                                </tr>
                            ))}
                            {(!booking.payments || booking.payments.length === 0) && (
                                <tr><td colSpan={4}>No payments recorded yet.</td></tr>
                            )}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={3}>Total</td>
                                <td className="num">{formatBDT(booking.total_amount)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="mb-invoice-footer">
                    <button type="button" className="pay-btn-outline" onClick={onClose}>Close</button>
                    <button type="button" className="booking-request-btn" onClick={() => window.print()}>
                        <Printer size={14} /> Print
                    </button>
                </div>
            </div>
        </div>
    );
}
