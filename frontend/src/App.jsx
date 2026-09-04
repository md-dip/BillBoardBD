import { BrowserRouter, Routes, Route, useLocation } from 'react-router-dom';
import FindBillboards from './client/pages/FindBillboards';
import BillboardDetail from './client/pages/BillboardDetail';
import MyBookings from './client/pages/MyBookings';
import ClientInvoice from './client/pages/Invoice';
import ClientHowItWorks from './client/pages/HowItWorks';
import ClientNavbar from './client/components/Navbar';
import Footer from './shared/components/Footer';
import OwnerNavbar from './owner/components/Navbar';
import AdminNavbar from './admin/components/Navbar';
import Home from './shared/pages/Home';
import HowItWorks from './shared/pages/HowItWorks';
import Login from './shared/pages/Login';
import Register from './shared/pages/Register';
import NotFound from './shared/pages/NotFound';
import ProtectedRoute from './shared/components/ProtectedRoute';
import DefaultNavbar from './shared/components/Navbar';
import { useAuth } from './shared/context/AuthContext';
import './App.css';
import AdminDashboard from './admin/pages/Dashboard';
import AdminBillboards from './admin/pages/Billboards';
import AdminBookings from './admin/pages/Bookings';
import AdminInvoice from './admin/pages/Invoice';
import AdminPermits from './admin/pages/Permits';
import AdminReports from './admin/pages/Reports';
import AdminSettings from './admin/pages/Settings';
import AdminPayouts from './admin/pages/Payouts';
import AdminPayoutReceipt from './admin/pages/PayoutReceipt';
import OwnerDashboard from './owner/pages/Dashboard';
import OwnerMyBillboards from './owner/pages/MyBillboards';
import OwnerBookingRequests from './owner/pages/BookingRequests';
import OwnerPayouts from './owner/pages/Payouts';
import OwnerPayoutReceipt from './owner/pages/PayoutReceipt';

function AppRoutes() {
    const { pathname } = useLocation();
    const { user } = useAuth();
    const isAdmin = pathname.startsWith('/admin');
    const isOwner = pathname.startsWith('/owner');
    const showClientChrome = !isAdmin && !isOwner;

    // On a shared/public page, an owner/admin gets their own navbar (a badge
    // linking back to /owner or /admin) instead of the client-only one -
    // only a logged-in client falls through to ClientNavbar.
    let navbar = <DefaultNavbar />;
    if (showClientChrome && user?.role === 'owner') navbar = <OwnerNavbar />;
    else if (showClientChrome && user?.role === 'admin') navbar = <AdminNavbar />;
    else if (showClientChrome && user) navbar = <ClientNavbar />;

    return (
        <div className="app-shell">
            {showClientChrome && navbar}
            <div className="app-main">
                <Routes>
                    <Route path="/" element={<Home />} />
                    <Route path="/billboards" element={<FindBillboards />} />
                    <Route path="/how-it-works" element={user?.role === 'client' ? <ClientHowItWorks /> : <HowItWorks />} />
                    <Route path="/login" element={<Login />} />
                    <Route path="/register" element={<Register />} />
                    <Route path="/billboards/:id" element={<BillboardDetail />} />
                    <Route path="/dashboard" element={<ProtectedRoute requireRole="client"><MyBookings /></ProtectedRoute>} />
                    <Route path="/bookings/:bookingId/invoice" element={<ProtectedRoute requireRole="client"><ClientInvoice /></ProtectedRoute>} />

                    <Route path="/admin" element={<ProtectedRoute requireRole="admin"><AdminDashboard /></ProtectedRoute>} />
                    <Route path="/admin/billboards" element={<ProtectedRoute requireRole="admin"><AdminBillboards /></ProtectedRoute>} />
                    <Route path="/admin/bookings" element={<ProtectedRoute requireRole="admin"><AdminBookings /></ProtectedRoute>} />
                    <Route path="/admin/bookings/:bookingId/invoice" element={<ProtectedRoute requireRole="admin"><AdminInvoice /></ProtectedRoute>} />
                    <Route path="/admin/permits" element={<ProtectedRoute requireRole="admin"><AdminPermits /></ProtectedRoute>} />
                    <Route path="/admin/reports" element={<ProtectedRoute requireRole="admin"><AdminReports /></ProtectedRoute>} />
                    <Route path="/admin/settings" element={<ProtectedRoute requireRole="admin"><AdminSettings /></ProtectedRoute>} />
                    <Route path="/admin/payouts" element={<ProtectedRoute requireRole="admin"><AdminPayouts /></ProtectedRoute>} />
                    <Route path="/admin/payouts/:payoutId/receipt" element={<ProtectedRoute requireRole="admin"><AdminPayoutReceipt /></ProtectedRoute>} />

                    <Route path="/owner" element={<ProtectedRoute requireRole="owner"><OwnerDashboard /></ProtectedRoute>} />
                    <Route path="/owner/billboards" element={<ProtectedRoute requireRole="owner"><OwnerMyBillboards /></ProtectedRoute>} />
                    <Route path="/owner/bookings" element={<ProtectedRoute requireRole="owner"><OwnerBookingRequests /></ProtectedRoute>} />
                    <Route path="/owner/payouts" element={<ProtectedRoute requireRole="owner"><OwnerPayouts /></ProtectedRoute>} />
                    <Route path="/owner/payouts/:payoutId/receipt" element={<ProtectedRoute requireRole="owner"><OwnerPayoutReceipt /></ProtectedRoute>} />

                    {/* Catch-all: any URL that matched none of the routes above. Must stay last. */}
                    <Route path="*" element={<NotFound />} />
                </Routes>
            </div>
            {showClientChrome && <Footer />}
        </div>
    );
}

export default function App() {
    return (
        <BrowserRouter>
            <AppRoutes />
        </BrowserRouter>
    );
}
