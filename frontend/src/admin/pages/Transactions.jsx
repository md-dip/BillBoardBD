import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';
import api from '../../shared/api/axios';
import AdminShell from '../components/AdminShell';
import { formatBDT } from '../../shared/utils/formatPrice';
import usePageTitle from '../../shared/hooks/usePageTitle';
import './Transactions.css';

// One screenful of ledger. The whole list is already in memory (the totals are
// summed from it), so paging is instant and the summary cards keep covering
// every transaction rather than just the page on show.
const PER_PAGE = 30;

// Every kind of money that can enter the platform. The slug doubles as the CSS
// suffix, so each badge owns its own complete rule in Transactions.css.
const TYPES = {
    booking_advance: { label: 'Booking advance', slug: 'booking-advance' },
    booking_balance: { label: 'Final payment', slug: 'final-payment' },
    listing_fee: { label: 'Board listing fee', slug: 'listing-fee' },
};

/**
 * The drill-down behind the dashboard's "Total revenue" and "Platform
 * commission" tiles - one row per transaction that actually entered the
 * platform, straight from /admin/reports/transactions (the same ledger the
 * tiles are aggregated from, so the two can never disagree).
 *
 * Both views render this one component; `view` only decides which figures are
 * on show. Revenue asks "what came in", commission asks "what did we keep, and
 * at what rate" - the rows are identical either way.
 */
export default function AdminTransactions({ view = 'revenue' }) {
    const isCommission = view === 'commission';

    usePageTitle(isCommission ? 'Admin Platform Commission' : 'Admin Total Revenue');

    const [transactions, setTransactions] = useState([]);
    const [totals, setTotals] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);

    useEffect(() => {
        api.get('/admin/reports/transactions')
            .then((res) => {
                setTransactions(res.data.data.transactions);
                setTotals(res.data.data.totals);
            })
            .catch((err) => console.error('Transactions load failed', err))
            .finally(() => setLoading(false));
    }, []);

    const title = isCommission ? 'Platform commission' : 'Total revenue';

    if (loading) {
        return (
            <AdminShell title={title}>
                <p className="admin-transactions-muted">Loading transactions...</p>
            </AdminShell>
        );
    }

    const bookingMoney = (totals?.gross ?? 0) - (totals?.listing_fees ?? 0);

    const pageCount = Math.max(1, Math.ceil(transactions.length / PER_PAGE));
    const firstOnPage = transactions.length === 0 ? 0 : (page - 1) * PER_PAGE + 1;
    const lastOnPage = Math.min(page * PER_PAGE, transactions.length);
    const visible = transactions.slice((page - 1) * PER_PAGE, page * PER_PAGE);

    // Turning a page puts you at the bottom of the previous one, so go back up
    // to the first row rather than making the admin scroll for it.
    function goToPage(next) {
        setPage(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Headline first, then what it is made of.
    const summary = isCommission
        ? [
            { slug: 'platform-income', label: 'Platform commission', value: formatBDT(totals?.platform_income ?? 0) },
            { slug: 'booking-commission', label: 'From booking commission', value: formatBDT(totals?.commission ?? 0) },
            { slug: 'listing-income', label: 'From board listing fees', value: formatBDT(totals?.listing_fees ?? 0) },
        ]
        : [
            { slug: 'total-revenue', label: 'Total revenue', value: formatBDT(totals?.gross ?? 0) },
            { slug: 'booking-money', label: 'From booking payments', value: formatBDT(bookingMoney) },
            { slug: 'listing-income', label: 'From board listing fees', value: formatBDT(totals?.listing_fees ?? 0) },
        ];

    return (
        <AdminShell title={title}>
            <Link to="/admin" className="admin-transactions-back-to-dashboard-link">
                <ArrowLeft size={14} /> Back to dashboard
            </Link>

            <p className="admin-transactions-intro">
                {isCommission
                    ? 'What the platform kept out of every transaction, and the rate it was taken at. A board listing fee has no owner split, so the platform keeps all of it.'
                    : 'Every payment that has entered the platform: booking advances, final payments and board listing fees. Money that could still be refunded is not listed until it cannot be.'}
            </p>

            <div className="admin-transactions-summary-grid">
                {summary.map((card) => (
                    <div className={`admin-transactions-summary-card-${card.slug}`} key={card.slug}>
                        <div className={`admin-transactions-summary-label-${card.slug}`}>{card.label}</div>
                        <div className={`admin-transactions-summary-value-${card.slug}`}>{card.value}</div>
                    </div>
                ))}
            </div>

            <div className="admin-transactions-table-card">
                <div className="admin-transactions-table-header">
                    <h2 className="admin-transactions-table-title">
                        {transactions.length} transaction{transactions.length === 1 ? '' : 's'}
                    </h2>
                </div>

                <div className="admin-transactions-table-scroll">
                    <table className="admin-transactions-table">
                        <thead>
                            <tr>
                                <th className="admin-transactions-date-heading">Date</th>
                                <th className="admin-transactions-type-heading">Type</th>
                                <th className="admin-transactions-billboard-heading">Billboard</th>
                                {isCommission ? null : <th className="admin-transactions-paid-by-heading">Paid by</th>}
                                <th className="admin-transactions-reference-heading">Reference</th>
                                <th className="admin-transactions-amount-heading">Amount</th>
                                {isCommission ? <th className="admin-transactions-rate-heading">Rate</th> : null}
                                {isCommission ? <th className="admin-transactions-cut-heading">Platform cut</th> : null}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((tx) => {
                                const type = TYPES[tx.type] ?? { label: tx.type, slug: 'booking-advance' };

                                return (
                                    <tr key={tx.id}>
                                        <td className="admin-transactions-date-cell">{String(tx.earned_at).slice(0, 10)}</td>
                                        <td>
                                            <span className={`admin-transactions-type-badge-${type.slug}`}>{type.label}</span>
                                        </td>
                                        <td className="admin-transactions-billboard-cell">{tx.billboard_title}</td>
                                        {isCommission ? null : (
                                            <td className="admin-transactions-paid-by-cell">
                                                {tx.payer_name || '-'}
                                                <span className="admin-transactions-payer-role">{tx.payer_role}</span>
                                            </td>
                                        )}
                                        <td className="admin-transactions-reference-cell">
                                            {tx.booking_id ? `Booking #${tx.booking_id}` : 'Listing fee'}
                                            {tx.brand_name ? <span className="admin-transactions-brand">{tx.brand_name}</span> : null}
                                        </td>
                                        <td className="admin-transactions-amount-cell">{formatBDT(tx.amount)}</td>
                                        {isCommission ? (
                                            <td className="admin-transactions-rate-cell">{Number(tx.commission_rate)}%</td>
                                        ) : null}
                                        {isCommission ? (
                                            <td className="admin-transactions-cut-cell">{formatBDT(tx.platform_cut)}</td>
                                        ) : null}
                                    </tr>
                                );
                            })}

                            {transactions.length === 0 && (
                                <tr>
                                    <td className="admin-transactions-empty-cell" colSpan={isCommission ? 7 : 6}>
                                        Nothing has been earned yet. A booking advance shows up here once admin and the
                                        owner have both approved it, and a listing fee once the board is approved.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="admin-transactions-pager">
                    <button
                        type="button"
                        className="admin-transactions-previous-btn"
                        onClick={() => goToPage(page - 1)}
                        disabled={page <= 1}
                    >
                        <ChevronLeft size={14} /> Previous
                    </button>

                    <span className="admin-transactions-pager-status">
                        {transactions.length === 0
                            ? 'Nothing to show'
                            : `Showing ${firstOnPage}-${lastOnPage} of ${transactions.length} - page ${page} of ${pageCount}`}
                    </span>

                    <button
                        type="button"
                        className="admin-transactions-next-btn"
                        onClick={() => goToPage(page + 1)}
                        disabled={page >= pageCount}
                    >
                        Next <ChevronRight size={14} />
                    </button>
                </div>
            </div>
        </AdminShell>
    );
}
