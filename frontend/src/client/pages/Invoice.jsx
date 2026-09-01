import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer } from 'lucide-react';
import api from '../../shared/api/axios';
import { formatBDT } from '../../shared/utils/formatPrice';
import './Invoice.css';

const KIND_LABEL = { advance: 'Advance invoice', final: 'Final invoice' };

export default function Invoice() {
    const { bookingId } = useParams();
    const [invoice, setInvoice] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        api.get(`/bookings/${bookingId}/invoice`)
            .then((res) => { if (alive) { setInvoice(res.data.data); setError(''); } })
            .catch((err) => { if (alive) setError(err.response?.data?.message || 'Could not load this invoice.'); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [bookingId]);

    return (
        <div className="client-invoice-page">
            <div className="client-invoice-topbar">
                <Link to="/dashboard" className="client-invoice-back-link">
                    <ArrowLeft size={16} /> Back to bookings
                </Link>
                {invoice && (
                    <button type="button" className="client-invoice-print-btn" onClick={() => window.print()}>
                        <Printer size={14} /> Print / PDF
                    </button>
                )}
            </div>

            {loading && <p className="client-invoice-status">Loading invoice…</p>}
            {!loading && error && (
                <div className="client-invoice-empty">
                    <p>{error}</p>
                    <Link to="/dashboard" className="client-invoice-empty-link">Go to my bookings</Link>
                </div>
            )}

            {!loading && invoice && (
                <>
                    <div className="client-invoice-heading">
                        <h1 className="client-invoice-heading-title">Invoice</h1>
                        <span className={`client-invoice-kind-pill client-invoice-kind-pill-${invoice.kind}`}>
                            {KIND_LABEL[invoice.kind] || invoice.kind}
                        </span>
                    </div>

                    <div className="client-invoice-doc">
                        <div className="client-invoice-doc-head">
                            <div className="client-invoice-brand">
                                <div className="client-invoice-brand-name">
                                    Billboard<span className="client-invoice-brand-accent">BD</span>
                                </div>
                                <div className="client-invoice-brand-line">{invoice.seller.address}</div>
                                <div className="client-invoice-brand-line">
                                    {invoice.seller.email} · {invoice.seller.phone}
                                </div>
                            </div>
                            <div className="client-invoice-meta">
                                <div className="client-invoice-meta-label">Invoice</div>
                                <div className="client-invoice-number">{invoice.number}</div>
                                <div className="client-invoice-meta-line">Issued: {invoice.issued_at}</div>
                                <div className="client-invoice-meta-line">Booking ref: #{invoice.booking_id}</div>
                            </div>
                        </div>

                        <div className="client-invoice-parties">
                            <div className="client-invoice-party">
                                <div className="client-invoice-party-label">Billed to</div>
                                <div className="client-invoice-party-name">{invoice.client.name}</div>
                                <div className="client-invoice-party-line">Client account</div>
                            </div>
                            <div className="client-invoice-party">
                                <div className="client-invoice-party-label">Billboard</div>
                                <div className="client-invoice-party-name">{invoice.billboard.title}</div>
                                <div className="client-invoice-party-line">{invoice.billboard.address}</div>
                                <div className="client-invoice-party-line">
                                    {invoice.billboard.size} · {invoice.billboard.type}
                                </div>
                            </div>
                        </div>

                        <div className="client-invoice-section-label">Campaign details</div>
                        <table className="client-invoice-lines">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th className="client-invoice-num">Days</th>
                                    <th className="client-invoice-num">Daily rate</th>
                                    <th className="client-invoice-num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div className="client-invoice-line-title">{invoice.line_item.description}</div>
                                        <div className="client-invoice-line-sub">
                                            {invoice.line_item.start_date} → {invoice.line_item.end_date}
                                        </div>
                                    </td>
                                    <td className="client-invoice-num">{invoice.line_item.days}</td>
                                    <td className="client-invoice-num">{formatBDT(invoice.line_item.daily_rate)}</td>
                                    <td className="client-invoice-num">{formatBDT(invoice.line_item.amount)}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={3}>Subtotal</td>
                                    <td className="client-invoice-num">{formatBDT(invoice.subtotal)}</td>
                                </tr>
                            </tfoot>
                        </table>

                        <div className="client-invoice-section-label">Payment history</div>
                        <table className="client-invoice-payments">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Transaction ID</th>
                                    <th className="client-invoice-num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.payments.length === 0 && (
                                    <tr><td colSpan={4}>No payments recorded yet.</td></tr>
                                )}
                                {invoice.payments.map((p, i) => (
                                    <tr key={i}>
                                        <td>{p.date || '-'}</td>
                                        <td className="client-invoice-method">{p.method || '-'}</td>
                                        <td>{p.transaction_ref || '-'}</td>
                                        <td className="client-invoice-num">{formatBDT(p.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={3}>Total paid</td>
                                    <td className="client-invoice-num">{formatBDT(invoice.amount_paid)}</td>
                                </tr>
                                <tr className="client-invoice-balance-row">
                                    <td colSpan={3}>Balance due</td>
                                    <td className="client-invoice-num">{formatBDT(invoice.balance_due)}</td>
                                </tr>
                            </tfoot>
                        </table>

                        {invoice.kind === 'advance' && invoice.balance_due > 0 && (
                            <div className="client-invoice-note">
                                This is an advance invoice for the {formatBDT(invoice.amount_paid)} paid so far.
                                The remaining {formatBDT(invoice.balance_due)} is due before the campaign goes live,
                                and a final invoice is issued once it is paid.
                            </div>
                        )}

                        <div className="client-invoice-footer-note">
                            Thank you for booking with BillboardBD. This invoice is computer-generated and valid without a signature.
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
