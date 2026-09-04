import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Printer } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './PayoutReceipt.css';

const METHOD_LABEL = { bkash: 'bKash', nagad: 'Nagad', bank: 'Bank transfer' };

export default function OwnerPayoutReceipt() {
    const { payoutId } = useParams();

    usePageTitle(`Payout Receipt #${payoutId}`);

    const [receipt, setReceipt] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        api.get(`/owner/payouts/${payoutId}/receipt`)
            .then((res) => { if (alive) { setReceipt(res.data.data); setError(''); } })
            .catch((err) => { if (alive) setError(err.response?.data?.message || 'Could not load this receipt.'); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [payoutId]);

    const details = receipt?.owner?.details || null;
    const isBank = details?.payout_method === 'bank';

    return (
        <OwnerShell title="Payout receipt">
        <div className="owner-payout-receipt-page">
            <div className="owner-payout-receipt-topbar">
                <Link to="/owner/payouts" className="owner-payout-receipt-back-link">
                    <ArrowLeft size={16} /> Back to payouts
                </Link>
                {receipt && (
                    <button type="button" className="owner-payout-receipt-print-btn" onClick={() => window.print()}>
                        <Printer size={14} /> Print / PDF
                    </button>
                )}
            </div>

            {loading && <p className="owner-payout-receipt-status">Loading receipt…</p>}
            {!loading && error && (
                <div className="owner-payout-receipt-empty">
                    <p>{error}</p>
                    <Link to="/owner/payouts" className="owner-payout-receipt-empty-link">Go to my payouts</Link>
                </div>
            )}

            {!loading && receipt && (
                <>
                    <div className="owner-payout-receipt-heading">
                        <h1 className="owner-payout-receipt-heading-title">Payout receipt</h1>
                    </div>

                    <div className="owner-payout-receipt-doc">
                        <div className="owner-payout-receipt-doc-head">
                            <div className="owner-payout-receipt-brand">
                                <div className="owner-payout-receipt-brand-name">
                                    Billboard<span className="owner-payout-receipt-brand-accent">BD</span>
                                </div>
                                <div className="owner-payout-receipt-brand-line">{receipt.platform.address}</div>
                                <div className="owner-payout-receipt-brand-line">
                                    {receipt.platform.email} · {receipt.platform.phone}
                                </div>
                            </div>
                            <div className="owner-payout-receipt-meta">
                                <div className="owner-payout-receipt-meta-label">Payout receipt</div>
                                <div className="owner-payout-receipt-number">{receipt.number}</div>
                                <div className="owner-payout-receipt-meta-line">Issued: {receipt.issued_at || '-'}</div>
                                <div className="owner-payout-receipt-meta-line">Payout ref: #{receipt.payout_id}</div>
                            </div>
                        </div>

                        <div className="owner-payout-receipt-parties">
                            <div className="owner-payout-receipt-party">
                                <div className="owner-payout-receipt-party-label">Paid to</div>
                                <div className="owner-payout-receipt-party-name">{receipt.owner.name || '-'}</div>
                                <div className="owner-payout-receipt-party-line">{receipt.owner.email || '-'}</div>
                            </div>
                            <div className="owner-payout-receipt-party">
                                <div className="owner-payout-receipt-party-label">Payment</div>
                                <div className="owner-payout-receipt-party-name">
                                    {METHOD_LABEL[receipt.method] || receipt.method || 'Not recorded'}
                                </div>
                                <div className="owner-payout-receipt-party-line">
                                    Reference: {receipt.reference || '-'}
                                </div>
                                <div className="owner-payout-receipt-party-line">
                                    Recorded by: {receipt.paid_by || '-'}
                                </div>
                            </div>
                        </div>

                        <div className="owner-payout-receipt-section-label">Payout account</div>
                        {details ? (
                            <div className="owner-payout-receipt-account-grid">
                                <div className="owner-payout-receipt-account-cell">
                                    <span className="owner-payout-receipt-account-label">Method</span>
                                    <span className="owner-payout-receipt-account-value">
                                        {METHOD_LABEL[details.payout_method] || details.payout_method || '-'}
                                    </span>
                                </div>
                                <div className="owner-payout-receipt-account-cell">
                                    <span className="owner-payout-receipt-account-label">
                                        {isBank ? 'Account holder name' : 'Account name'}
                                    </span>
                                    <span className="owner-payout-receipt-account-value">
                                        {details.payout_account_name || '-'}
                                    </span>
                                </div>
                                <div className="owner-payout-receipt-account-cell">
                                    <span className="owner-payout-receipt-account-label">
                                        {isBank ? 'Account number' : `${METHOD_LABEL[details.payout_method] || 'Account'} number`}
                                    </span>
                                    <span className="owner-payout-receipt-account-value">
                                        {details.payout_account_number || '-'}
                                    </span>
                                </div>
                                {isBank && (
                                    <>
                                        <div className="owner-payout-receipt-account-cell">
                                            <span className="owner-payout-receipt-account-label">Bank name</span>
                                            <span className="owner-payout-receipt-account-value">
                                                {details.payout_bank_name || '-'}
                                            </span>
                                        </div>
                                        <div className="owner-payout-receipt-account-cell">
                                            <span className="owner-payout-receipt-account-label">Branch</span>
                                            <span className="owner-payout-receipt-account-value">
                                                {details.payout_branch || '-'}
                                            </span>
                                        </div>
                                    </>
                                )}
                            </div>
                        ) : (
                            <p className="owner-payout-receipt-account-missing">
                                Payout account details were not recorded for this payout.
                            </p>
                        )}

                        <div className="owner-payout-receipt-section-label">Settled bookings</div>
                        <table className="owner-payout-receipt-lines">
                            <thead>
                                <tr>
                                    <th>Billboard</th>
                                    <th>Booking</th>
                                    <th className="owner-payout-receipt-num">Gross</th>
                                    <th className="owner-payout-receipt-num">Commission</th>
                                    <th className="owner-payout-receipt-num">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                {receipt.line_items.length === 0 && (
                                    <tr><td colSpan={5}>No settled bookings were attached to this payout.</td></tr>
                                )}
                                {receipt.line_items.map((li, i) => (
                                    <tr key={i}>
                                        <td>
                                            <div className="owner-payout-receipt-line-title">{li.billboard_title || '-'}</div>
                                            <div className="owner-payout-receipt-line-sub">
                                                {li.start_date || '-'} → {li.end_date || '-'}
                                            </div>
                                        </td>
                                        <td>#{li.booking_id || '-'}</td>
                                        <td className="owner-payout-receipt-num">{formatBDT(li.gross)}</td>
                                        <td className="owner-payout-receipt-num">− {formatBDT(li.commission)}</td>
                                        <td className="owner-payout-receipt-num">{formatBDT(li.net)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colSpan={4}>Gross</td>
                                    <td className="owner-payout-receipt-num">{formatBDT(receipt.totals.gross)}</td>
                                </tr>
                                <tr className="owner-payout-receipt-split-row">
                                    <td colSpan={4}>Platform commission</td>
                                    <td className="owner-payout-receipt-num">− {formatBDT(receipt.totals.commission)}</td>
                                </tr>
                                <tr className="owner-payout-receipt-total-row">
                                    <td colSpan={4}>Net paid</td>
                                    <td className="owner-payout-receipt-num">{formatBDT(receipt.totals.amount)}</td>
                                </tr>
                            </tfoot>
                        </table>

                        {!receipt.totals.amount_matches_lines && (
                            <div className="owner-payout-receipt-note">
                                The paid amount differs from the sum of the settled-booking lines above.
                                Contact admin if this looks wrong.
                            </div>
                        )}

                        {receipt.note && (
                            <div className="owner-payout-receipt-note">{receipt.note}</div>
                        )}

                        <div className="owner-payout-receipt-footer-note">
                            This receipt is computer-generated by BillboardBD and valid without a signature.
                        </div>
                    </div>
                </>
            )}
        </div>
        </OwnerShell>
    );
}
