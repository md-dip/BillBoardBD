import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './PayoutReceipt.css';

const METHOD_LABEL = { bkash: 'bKash', nagad: 'Nagad', bank: 'Bank transfer' };

export default function AdminPayoutReceipt() {
    const { payoutId } = useParams();

    usePageTitle(`Admin Payout Receipt #${payoutId}`);

    const [receipt, setReceipt] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        api.get(`/admin/payouts/${payoutId}/receipt`)
            .then((res) => { if (alive) { setReceipt(res.data.data); setError(''); } })
            .catch((err) => { if (alive) setError(err.response?.data?.message || 'Could not load this receipt.'); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [payoutId]);

    const details = receipt?.owner?.details || null;
    const isBank = details?.payout_method === 'bank';

    return (
        <AdminShell title="Payout receipt">
        <div className="admin-payout-receipt-page">
            <div className="admin-payout-receipt-topbar">
                <Link to="/admin/payouts" className="admin-payout-receipt-back-link">
                    <ArrowLeft size={16} /> Back to payouts
                </Link>
                {receipt && (
                    <button type="button" className="admin-payout-receipt-print-btn" onClick={() => window.print()}>
                        <Printer size={14} /> Print / PDF
                    </button>
                )}
            </div>

            {loading && <p className="admin-payout-receipt-status">Loading receipt…</p>}
            {!loading && error && (
                <div className="admin-payout-receipt-empty">
                    <p>{error}</p>
                    <Link to="/admin/payouts" className="admin-payout-receipt-empty-link">Back to payouts</Link>
                </div>
            )}

            {!loading && receipt && (
                <>
                    <div className="admin-payout-receipt-heading">
                        <h1 className="admin-payout-receipt-heading-title">Payout receipt</h1>
                    </div>

                    <div className="admin-payout-receipt-doc">
                        <div className="admin-payout-receipt-doc-head">
                            <div className="admin-payout-receipt-brand">
                                <div className="admin-payout-receipt-brand-name">
                                    Billboard<span className="admin-payout-receipt-brand-accent">BD</span>
                                </div>
                                <div className="admin-payout-receipt-brand-line">{receipt.platform.address}</div>
                                <div className="admin-payout-receipt-brand-line">
                                    {receipt.platform.email} · {receipt.platform.phone}
                                </div>
                            </div>
                            <div className="admin-payout-receipt-meta">
                                <div className="admin-payout-receipt-meta-label">Payout receipt</div>
                                <div className="admin-payout-receipt-number">{receipt.number}</div>
                                <div className="admin-payout-receipt-meta-line">Issued: {receipt.issued_at || '-'}</div>
                                <div className="admin-payout-receipt-meta-line">Payout ref: #{receipt.payout_id}</div>
                            </div>
                        </div>

                        <div className="admin-payout-receipt-parties">
                            <div className="admin-payout-receipt-party">
                                <div className="admin-payout-receipt-party-label">Paid to</div>
                                <div className="admin-payout-receipt-party-name">{receipt.owner.name || '-'}</div>
                                <div className="admin-payout-receipt-party-line">{receipt.owner.email || '-'}</div>
                            </div>
                            <div className="admin-payout-receipt-party">
                                <div className="admin-payout-receipt-party-label">Payment</div>
                                <div className="admin-payout-receipt-party-name">
                                    {METHOD_LABEL[receipt.method] || receipt.method || 'Not recorded'}
                                </div>
                                <div className="admin-payout-receipt-party-line">
                                    Reference: {receipt.reference || '-'}
                                </div>
                                <div className="admin-payout-receipt-party-line">
                                    Recorded by: {receipt.paid_by || '-'}
                                </div>
                            </div>
                        </div>

                        <div className="admin-payout-receipt-section-label">Payout account</div>
                        {details ? (
                            <div className="admin-payout-receipt-account-grid">
                                <div className="admin-payout-receipt-account-cell">
                                    <span className="admin-payout-receipt-account-label">Method</span>
                                    <span className="admin-payout-receipt-account-value">
                                        {METHOD_LABEL[details.payout_method] || details.payout_method || '-'}
                                    </span>
                                </div>
                                <div className="admin-payout-receipt-account-cell">
                                    <span className="admin-payout-receipt-account-label">
                                        {isBank ? 'Account holder name' : 'Account name'}
                                    </span>
                                    <span className="admin-payout-receipt-account-value">
                                        {details.payout_account_name || '-'}
                                    </span>
                                </div>
                                <div className="admin-payout-receipt-account-cell">
                                    <span className="admin-payout-receipt-account-label">
                                        {isBank ? 'Account number' : `${METHOD_LABEL[details.payout_method] || 'Account'} number`}
                                    </span>
                                    <span className="admin-payout-receipt-account-value">
                                        {details.payout_account_number || '-'}
                                    </span>
                                </div>
                                {isBank && (
                                    <>
                                        <div className="admin-payout-receipt-account-cell">
                                            <span className="admin-payout-receipt-account-label">Bank name</span>
                                            <span className="admin-payout-receipt-account-value">
                                                {details.payout_bank_name || '-'}
                                            </span>
                                        </div>
                                        <div className="admin-payout-receipt-account-cell">
                                            <span className="admin-payout-receipt-account-label">Branch</span>
                                            <span className="admin-payout-receipt-account-value">
                                                {details.payout_branch || '-'}
                                            </span>
                                        </div>
                                    </>
                                )}
                            </div>
                        ) : (
                            <p className="admin-payout-receipt-account-missing">
                                Payout account details were not recorded for this payout.
                            </p>
                        )}

                        <div className="admin-payout-receipt-section-label">Settled bookings</div>
                        <table className="admin-payout-receipt-lines">
                            <thead>
                                <tr>
                                    <th>Billboard</th>
                                    <th>Booking</th>
                                    <th className="admin-payout-receipt-num">Gross</th>
                                    <th className="admin-payout-receipt-num">Commission</th>
                                    <th className="admin-payout-receipt-num">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                {receipt.line_items.length === 0 && (
                                    <tr><td colSpan={5}>No settled bookings were attached to this payout.</td></tr>
                                )}
                                {receipt.line_items.map((li, i) => (
                                    <tr key={i}>
                                        <td>
                                            <div className="admin-payout-receipt-line-title">{li.billboard_title || '-'}</div>
                                            <div className="admin-payout-receipt-line-sub">
                                                {li.start_date || '-'} → {li.end_date || '-'}
                                            </div>
                                        </td>
                                        <td>#{li.booking_id || '-'}</td>
                                        <td className="admin-payout-receipt-num">{formatBDT(li.gross)}</td>
                                        <td className="admin-payout-receipt-num">− {formatBDT(li.commission)}</td>
                                        <td className="admin-payout-receipt-num">{formatBDT(li.net)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={4}>Gross</td>
                                    <td className="admin-payout-receipt-num">{formatBDT(receipt.totals.gross)}</td>
                                </tr>
                                <tr className="admin-payout-receipt-split-row">
                                    <td colSpan={4}>Platform commission</td>
                                    <td className="admin-payout-receipt-num">− {formatBDT(receipt.totals.commission)}</td>
                                </tr>
                                <tr className="admin-payout-receipt-total-row">
                                    <td colSpan={4}>Net paid</td>
                                    <td className="admin-payout-receipt-num">{formatBDT(receipt.totals.amount)}</td>
                                </tr>
                            </tfoot>
                        </table>

                        {!receipt.totals.amount_matches_lines && (
                            <div className="admin-payout-receipt-note">
                                The paid amount differs from the sum of the settled-booking lines above.
                            </div>
                        )}

                        {receipt.note && (
                            <div className="admin-payout-receipt-note">{receipt.note}</div>
                        )}

                        <div className="admin-payout-receipt-footer-note">
                            Internal copy - shows the gross, the platform commission and the net paid to the owner.
                        </div>
                    </div>
                </>
            )}
        </div>
        </AdminShell>
    );
}
