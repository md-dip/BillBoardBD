import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';
import api from '../../shared/api/axios';
import OwnerShell from '../components/OwnerShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Transactions.css';

// One screenful of ledger. The whole list is already in memory (the totals are
// summed from it), so paging is instant and the summary cards keep covering
// every transaction rather than just the page on show.
const PER_PAGE = 30;

// The two ways money lands on an owner's board. The slug doubles as the CSS
// suffix, so each badge owns its own complete rule in Transactions.css.
const TYPES = {
    booking_advance: { label: 'Booking advance', slug: 'booking-advance' },
    booking_balance: { label: 'Final payment', slug: 'final-payment' },
};

// Where a row's earnings have got to. Every earned taka is in exactly one of
// these, which is what explains a payout not moving the lifetime figure.
const PAYOUT_STATUSES = {
    paid_out: { label: 'Paid out', slug: 'paid-out' },
    ready: { label: 'Ready for payout', slug: 'ready' },
    awaiting_verification: { label: 'Awaiting verification', slug: 'awaiting-verification' },
    in_progress: { label: 'In progress', slug: 'in-progress' },
};

/**
 * The drill-down behind the owner dashboard's "Revenue (BDT)" tile - every
 * payment collected on this owner's boards, and what they keep of each once
 * the platform's commission comes out.
 *
 * Deliberately its own page rather than a shared one with the admin's: an
 * owner is asking a different question (what did MY boards earn ME) and must
 * never see another owner's money or the platform-wide totals.
 */
export default function OwnerTransactions() {
    usePageTitle('My Transactions');

    const [transactions, setTransactions] = useState([]);
    const [totals, setTotals] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);

    useEffect(() => {
        api.get('/owner/reports/transactions')
            .then((res) => {
                setTransactions(res.data.data.transactions);
                setTotals(res.data.data.totals);
            })
            .catch((err) => console.error('Owner transactions load failed', err))
            .finally(() => setLoading(false));
    }, []);

    if (loading) {
        return (
            <OwnerShell title="Revenue">
                <p className="owner-transactions-muted">Loading transactions...</p>
            </OwnerShell>
        );
    }

    const pageCount = Math.max(1, Math.ceil(transactions.length / PER_PAGE));
    const firstOnPage = transactions.length === 0 ? 0 : (page - 1) * PER_PAGE + 1;
    const lastOnPage = Math.min(page * PER_PAGE, transactions.length);
    const visible = transactions.slice((page - 1) * PER_PAGE, page * PER_PAGE);

    // Turning a page puts you at the bottom of the previous one, so go back up
    // to the first row rather than making the owner scroll for it.
    function goToPage(next) {
        setPage(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    const summary = [
        { slug: 'collected', label: 'Revenue collected', value: formatBDT(totals?.collected ?? 0) },
        { slug: 'platform-cut', label: 'Platform commission', value: formatBDT(totals?.platform_cut ?? 0) },
        { slug: 'earnings', label: 'Your earnings (all time)', value: formatBDT(totals?.earnings ?? 0) },
    ];

    // The same earnings figure, split by where the money actually is now.
    const journey = [
        { slug: 'paid-out', label: 'Paid out to you', value: formatBDT(totals?.paid_out ?? 0) },
        { slug: 'ready', label: 'Ready for payout', value: formatBDT(totals?.ready_for_payout ?? 0) },
        // Strictly what the admin is holding up: paid in full, proof uploaded.
        // Same bookings as the "Awaiting Admin" tab on Booking Requests, and
        // nothing else - money whose client still owes the balance, or whose
        // proof has not been uploaded, gets no card of its own here. Each row
        // in the table below still says which of those it is.
        { slug: 'awaiting-verification', label: 'Awaiting verification', value: formatBDT(totals?.awaiting_verification ?? 0) },
    ];

    return (
        <OwnerShell title="Revenue">
            <Link to="/owner" className="owner-transactions-back-to-dashboard-link">
                <ArrowLeft size={14} /> Back to dashboard
            </Link>

            <div className="owner-transactions-summary-grid">
                {summary.map((card) => (
                    <div className={`owner-transactions-summary-card-${card.slug}`} key={card.slug}>
                        <div className={`owner-transactions-summary-label-${card.slug}`}>{card.label}</div>
                        <div className={`owner-transactions-summary-value-${card.slug}`}>{card.value}</div>
                    </div>
                ))}
            </div>

            <div className="owner-transactions-journey-grid">
                {journey.map((card) => (
                    <div className={`owner-transactions-journey-card-${card.slug}`} key={card.slug}>
                        <div className={`owner-transactions-journey-label-${card.slug}`}>{card.label}</div>
                        <div className={`owner-transactions-journey-value-${card.slug}`}>{card.value}</div>
                    </div>
                ))}
            </div>

            <div className="owner-transactions-table-card">
                <div className="owner-transactions-table-header">
                    <h2 className="owner-transactions-table-title">
                        {transactions.length} transaction{transactions.length === 1 ? '' : 's'}
                    </h2>
                </div>

                <div className="owner-transactions-table-scroll">
                    <table className="owner-transactions-table">
                        <thead>
                            <tr>
                                <th className="owner-transactions-date-heading">Date</th>
                                <th className="owner-transactions-type-heading">Type</th>
                                <th className="owner-transactions-billboard-heading">Billboard</th>
                                <th className="owner-transactions-client-heading">Client</th>
                                <th className="owner-transactions-amount-heading">Client paid</th>
                                <th className="owner-transactions-cut-heading">Commission</th>
                                <th className="owner-transactions-earning-heading">You earned</th>
                                <th className="owner-transactions-status-heading">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((tx) => {
                                const type = TYPES[tx.type] ?? { label: tx.type, slug: 'booking-advance' };
                                const payoutStatus = PAYOUT_STATUSES[tx.payout_status] ?? PAYOUT_STATUSES.in_progress;

                                return (
                                    <tr key={tx.id}>
                                        <td className="owner-transactions-date-cell">{String(tx.earned_at).slice(0, 10)}</td>
                                        <td>
                                            <span className={`owner-transactions-type-badge-${type.slug}`}>{type.label}</span>
                                        </td>
                                        <td className="owner-transactions-billboard-cell">
                                            {tx.billboard_title}
                                            <span className="owner-transactions-booking-ref">Booking #{tx.booking_id}</span>
                                        </td>
                                        <td className="owner-transactions-client-cell">
                                            {tx.client_name || '-'}
                                            {tx.brand_name ? <span className="owner-transactions-brand">{tx.brand_name}</span> : null}
                                        </td>
                                        <td className="owner-transactions-amount-cell">{formatBDT(tx.amount)}</td>
                                        <td className="owner-transactions-cut-cell">
                                            -{formatBDT(tx.platform_cut)}
                                            <span className="owner-transactions-rate">{Number(tx.commission_rate)}%</span>
                                        </td>
                                        <td className="owner-transactions-earning-cell">{formatBDT(tx.owner_earning)}</td>
                                        <td className="owner-transactions-status-cell">
                                            <span className={`owner-transactions-status-badge-${payoutStatus.slug}`}>
                                                {payoutStatus.label}
                                            </span>
                                            {tx.paid_out_at && (
                                                <span className="owner-transactions-paid-out-on">
                                                    {String(tx.paid_out_at).slice(0, 10)}
                                                    {tx.payout_reference ? ` - ${tx.payout_reference}` : ''}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}

                            {transactions.length === 0 && (
                                <tr>
                                    <td className="owner-transactions-empty-cell" colSpan={8}>
                                        Nothing collected yet. A booking shows up here once the admin and you have both
                                        approved it and the client&apos;s advance has cleared.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="owner-transactions-pager">
                    <button
                        type="button"
                        className="owner-transactions-previous-btn"
                        onClick={() => goToPage(page - 1)}
                        disabled={page <= 1}
                    >
                        <ChevronLeft size={14} /> Previous
                    </button>

                    <span className="owner-transactions-pager-status">
                        {transactions.length === 0
                            ? 'Nothing to show'
                            : `Showing ${firstOnPage}-${lastOnPage} of ${transactions.length} - page ${page} of ${pageCount}`}
                    </span>

                    <button
                        type="button"
                        className="owner-transactions-next-btn"
                        onClick={() => goToPage(page + 1)}
                        disabled={page >= pageCount}
                    >
                        Next <ChevronRight size={14} />
                    </button>
                </div>
            </div>
        </OwnerShell>
    );
}
