import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Invoice.css';

const KIND_LABEL = { advance: 'Advance invoice', final: 'Final invoice' };

export default function OwnerInvoice() {
    const { bookingId } = useParams();

    usePageTitle(`Invoice #${bookingId}`);

    const [invoice, setInvoice] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        api.get(`/owner/bookings/${bookingId}/invoice`)
            .then((res) => { if (alive) { setInvoice(res.data.data); setError(''); } })
            .catch((err) => { if (alive) setError(err.response?.data?.message || 'Could not load this invoice.'); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [bookingId]);

    return (
        <OwnerShell title="Invoice">
            <div className="owner-invoice-topbar">
                <Link to="/owner/bookings" className="owner-invoice-back-link">
                    <ArrowLeft size={16} /> Back to booking requests
                </Link>
                {invoice && (
                    <button type="button" className="owner-invoice-print-btn" onClick={() => window.print()}>
                        <Printer size={14} /> Print / PDF
                    </button>
                )}
            </div>

            {loading && <p className="owner-invoice-status">Loading invoice…</p>}
            {!loading && error && (
                <div className="owner-invoice-empty">
                    <p>{error}</p>
                    <Link to="/owner/bookings" className="owner-invoice-empty-link">Back to booking requests</Link>
                </div>
            )}

            {!loading && invoice && (
                <div className="owner-invoice-doc">
                    <div className="owner-invoice-doc-head">
                        <div className="owner-invoice-brand">
                            <div className="owner-invoice-brand-name">
                                Billboard<span className="owner-invoice-brand-accent">BD</span>
                            </div>
                            <div className="owner-invoice-brand-line">{invoice.seller.address}</div>
                            <div className="owner-invoice-brand-line">
                                {invoice.seller.email} · {invoice.seller.phone}
                            </div>
                        </div>
                        <div className="owner-invoice-meta">
                            <div className="owner-invoice-meta-label">Invoice</div>
                            <div className="owner-invoice-number">{invoice.number}</div>
                            <div className="owner-invoice-meta-line">Issued: {invoice.issued_at}</div>
                            <div className="owner-invoice-meta-line">Booking ref: #{invoice.booking_id}</div>
                            <span className={`owner-invoice-kind-pill owner-invoice-kind-pill-${invoice.kind}`}>
                                {KIND_LABEL[invoice.kind] || invoice.kind}
                            </span>
                        </div>
                    </div>

                    <div className="owner-invoice-parties">
                        <div className="owner-invoice-party">
                            <div className="owner-invoice-party-label">Billed to</div>
                            <div className="owner-invoice-party-name">{invoice.client.name}</div>
                            <div className="owner-invoice-party-line">{invoice.client.email}</div>
                        </div>
                        <div className="owner-invoice-party">
                            <div className="owner-invoice-party-label">Billboard</div>
                            <div className="owner-invoice-party-name">{invoice.billboard.title}</div>
                            <div className="owner-invoice-party-line">{invoice.billboard.address}</div>
                            <div className="owner-invoice-party-line">
                                {invoice.billboard.size} · {invoice.billboard.type}
                            </div>
                        </div>
                    </div>

                    <div className="owner-invoice-section-label">Campaign details</div>
                    <div className="owner-invoice-lines-wrap">
                    <table className="owner-invoice-lines">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th className="owner-invoice-num">Days</th>
                                <th className="owner-invoice-num">Daily rate</th>
                                <th className="owner-invoice-num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div className="owner-invoice-line-title">{invoice.line_item.description}</div>
                                    <div className="owner-invoice-line-sub">
                                        {invoice.line_item.start_date} → {invoice.line_item.end_date}
                                    </div>
                                </td>
                                <td className="owner-invoice-num">{invoice.line_item.days}</td>
                                <td className="owner-invoice-num">{formatBDT(invoice.line_item.daily_rate)}</td>
                                <td className="owner-invoice-num">{formatBDT(invoice.line_item.amount)}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={3}>Subtotal</td>
                                <td className="owner-invoice-num">{formatBDT(invoice.subtotal)}</td>
                            </tr>
                            <tr className="owner-invoice-split-row">
                                <td colSpan={3}>Platform commission ({invoice.commission_rate}%)</td>
                                <td className="owner-invoice-num">− {formatBDT(invoice.commission_amount)}</td>
                            </tr>
                            <tr className="owner-invoice-owner-row">
                                <td colSpan={3}>Payable to you</td>
                                <td className="owner-invoice-num">{formatBDT(invoice.owner_payable)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>

                    <div className="owner-invoice-section-label">Payment history</div>
                    <div className="owner-invoice-payments-wrap">
                    <table className="owner-invoice-payments">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Transaction ID</th>
                                <th className="owner-invoice-num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.payments.length === 0 && (
                                <tr><td colSpan={4}>No payments recorded yet.</td></tr>
                            )}
                            {invoice.payments.map((p, i) => (
                                <tr key={i}>
                                    <td>{p.date || '-'}</td>
                                    <td className="owner-invoice-method">{p.method || '-'}</td>
                                    <td>{p.transaction_ref || '-'}</td>
                                    <td className="owner-invoice-num">{formatBDT(p.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={3}>Total paid</td>
                                <td className="owner-invoice-num">{formatBDT(invoice.amount_paid)}</td>
                            </tr>
                            <tr className="owner-invoice-balance-row">
                                <td colSpan={3}>Balance due</td>
                                <td className="owner-invoice-num">{formatBDT(invoice.balance_due)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>

                    <div className="owner-invoice-footer-note">
                        Your copy - includes the platform commission and the amount payable to you for this booking.
                    </div>
                </div>
            )}
        </OwnerShell>
    );
}
