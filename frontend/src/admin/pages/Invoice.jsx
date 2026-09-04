import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Invoice.css';

const KIND_LABEL = { advance: 'Advance invoice', final: 'Final invoice' };

export default function AdminInvoice() {
    const { bookingId } = useParams();

    usePageTitle(`Admin Invoice #${bookingId}`);

    const [invoice, setInvoice] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        api.get(`/admin/bookings/${bookingId}/invoice`)
            .then((res) => { if (alive) { setInvoice(res.data.data); setError(''); } })
            .catch((err) => { if (alive) setError(err.response?.data?.message || 'Could not load this invoice.'); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [bookingId]);

    return (
        <AdminShell title="Invoice">
            <div className="admin-invoice-topbar">
                <Link to="/admin/bookings" className="admin-invoice-back-link">
                    <ArrowLeft size={16} /> Back to bookings
                </Link>
                {invoice && (
                    <button type="button" className="admin-invoice-print-btn" onClick={() => window.print()}>
                        <Printer size={14} /> Print / PDF
                    </button>
                )}
            </div>

            {loading && <p className="admin-invoice-status">Loading invoice…</p>}
            {!loading && error && (
                <div className="admin-invoice-empty">
                    <p>{error}</p>
                    <Link to="/admin/bookings" className="admin-invoice-empty-link">Back to bookings</Link>
                </div>
            )}

            {!loading && invoice && (
                <div className="admin-invoice-doc">
                    <div className="admin-invoice-doc-head">
                        <div className="admin-invoice-brand">
                            <div className="admin-invoice-brand-name">
                                Billboard<span className="admin-invoice-brand-accent">BD</span>
                            </div>
                            <div className="admin-invoice-brand-line">{invoice.seller.address}</div>
                            <div className="admin-invoice-brand-line">
                                {invoice.seller.email} · {invoice.seller.phone}
                            </div>
                        </div>
                        <div className="admin-invoice-meta">
                            <div className="admin-invoice-meta-label">Invoice</div>
                            <div className="admin-invoice-number">{invoice.number}</div>
                            <div className="admin-invoice-meta-line">Issued: {invoice.issued_at}</div>
                            <div className="admin-invoice-meta-line">Booking ref: #{invoice.booking_id}</div>
                            <span className={`admin-invoice-kind-pill admin-invoice-kind-pill-${invoice.kind}`}>
                                {KIND_LABEL[invoice.kind] || invoice.kind}
                            </span>
                        </div>
                    </div>

                    <div className="admin-invoice-parties">
                        <div className="admin-invoice-party">
                            <div className="admin-invoice-party-label">Billed to</div>
                            <div className="admin-invoice-party-name">{invoice.client.name}</div>
                            <div className="admin-invoice-party-line">{invoice.client.email}</div>
                        </div>
                        <div className="admin-invoice-party">
                            <div className="admin-invoice-party-label">Billboard</div>
                            <div className="admin-invoice-party-name">{invoice.billboard.title}</div>
                            <div className="admin-invoice-party-line">{invoice.billboard.address}</div>
                            <div className="admin-invoice-party-line">
                                {invoice.billboard.size} · {invoice.billboard.type}
                            </div>
                        </div>
                    </div>

                    <div className="admin-invoice-section-label">Campaign details</div>
                    <table className="admin-invoice-lines">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th className="admin-invoice-num">Days</th>
                                <th className="admin-invoice-num">Daily rate</th>
                                <th className="admin-invoice-num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div className="admin-invoice-line-title">{invoice.line_item.description}</div>
                                    <div className="admin-invoice-line-sub">
                                        {invoice.line_item.start_date} → {invoice.line_item.end_date}
                                    </div>
                                </td>
                                <td className="admin-invoice-num">{invoice.line_item.days}</td>
                                <td className="admin-invoice-num">{formatBDT(invoice.line_item.daily_rate)}</td>
                                <td className="admin-invoice-num">{formatBDT(invoice.line_item.amount)}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={3}>Subtotal</td>
                                <td className="admin-invoice-num">{formatBDT(invoice.subtotal)}</td>
                            </tr>
                            <tr className="admin-invoice-split-row">
                                <td colSpan={3}>Platform commission ({invoice.commission_rate}%)</td>
                                <td className="admin-invoice-num">− {formatBDT(invoice.commission_amount)}</td>
                            </tr>
                            <tr className="admin-invoice-owner-row">
                                <td colSpan={3}>Payable to owner</td>
                                <td className="admin-invoice-num">{formatBDT(invoice.owner_payable)}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <div className="admin-invoice-section-label">Payment history</div>
                    <table className="admin-invoice-payments">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Transaction ID</th>
                                <th className="admin-invoice-num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.payments.length === 0 && (
                                <tr><td colSpan={4}>No payments recorded yet.</td></tr>
                            )}
                            {invoice.payments.map((p, i) => (
                                <tr key={i}>
                                    <td>{p.date || '-'}</td>
                                    <td className="admin-invoice-method">{p.method || '-'}</td>
                                    <td>{p.transaction_ref || '-'}</td>
                                    <td className="admin-invoice-num">{formatBDT(p.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={3}>Total paid</td>
                                <td className="admin-invoice-num">{formatBDT(invoice.amount_paid)}</td>
                            </tr>
                            <tr className="admin-invoice-balance-row">
                                <td colSpan={3}>Balance due</td>
                                <td className="admin-invoice-num">{formatBDT(invoice.balance_due)}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <div className="admin-invoice-footer-note">
                        Internal copy - includes the platform commission and payable-to-owner split, which the client&apos;s
                        invoice does not show.
                    </div>
                </div>
            )}
        </AdminShell>
    );
}
