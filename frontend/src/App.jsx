import { BrowserRouter, Routes, Route, useLocation } from 'react-router-dom';
import FindBillboards from './client/pages/FindBillboards';
import BillboardDetail from './client/pages/BillboardDetail';
import MyBookings from './client/pages/MyBookings';
import ClientHowItWorks from './client/pages/HowItWorks';
import ClientNavbar from './client/components/Navbar';
import Footer from './client/components/Footer';
import Home from './shared/pages/Home';
import HowItWorks from './shared/pages/HowItWorks';
import Login from './shared/pages/Login';
import Register from './shared/pages/Register';
import ProtectedRoute from './shared/components/ProtectedRoute';
import DefaultNavbar from './shared/components/Navbar';
import { useAuth } from './shared/context/AuthContext';
import AdminDashboard from './pages/admin/Dashboard';
import AdminBillboards from './pages/admin/BillboardsPage';
import AdminBookings from './pages/admin/BookingsPage';
import AdminPermits from './pages/admin/PermitsPage';
import AdminReports from './pages/admin/ReportsPage';
import AdminSettings from './pages/admin/SettingsPage';
import AdminPayouts from './pages/admin/PayoutsPage';
import OwnerDashboard from './pages/owner/Dashboard';
import OwnerBillboards from './pages/owner/BillboardsPage';
import OwnerBookings from './pages/owner/BookingsPage';
import OwnerPayouts from './pages/owner/PayoutsPage';

function AppRoutes() {
    const { pathname } = useLocation();
    const { user } = useAuth();
    const isAdmin = pathname.startsWith('/admin');
    const isOwner = pathname.startsWith('/owner');
    const showClientChrome = !isAdmin && !isOwner;

    return (
        <>
            {showClientChrome && (user ? <ClientNavbar /> : <DefaultNavbar />)}
            <Routes>
                <Route path="/" element={<Home />} />
                <Route path="/billboards" element={<FindBillboards />} />
                <Route path="/how-it-works" element={user?.role === 'client' ? <ClientHowItWorks /> : <HowItWorks />} />
                <Route path="/login" element={<Login />} />
                <Route path="/register" element={<Register />} />
                <Route path="/billboards/:id" element={<BillboardDetail />} />
                <Route path="/dashboard" element={<ProtectedRoute><MyBookings /></ProtectedRoute>} />

                <Route path="/admin" element={<ProtectedRoute requireRole="admin"><AdminDashboard /></ProtectedRoute>} />
                <Route path="/admin/billboards" element={<ProtectedRoute requireRole="admin"><AdminBillboards /></ProtectedRoute>} />
                <Route path="/admin/bookings" element={<ProtectedRoute requireRole="admin"><AdminBookings /></ProtectedRoute>} />
                <Route path="/admin/permits" element={<ProtectedRoute requireRole="admin"><AdminPermits /></ProtectedRoute>} />
                <Route path="/admin/reports" element={<ProtectedRoute requireRole="admin"><AdminReports /></ProtectedRoute>} />
                <Route path="/admin/settings" element={<ProtectedRoute requireRole="admin"><AdminSettings /></ProtectedRoute>} />
                <Route path="/admin/payouts" element={<ProtectedRoute requireRole="admin"><AdminPayouts /></ProtectedRoute>} />

                <Route path="/owner" element={<ProtectedRoute requireRole="owner"><OwnerDashboard /></ProtectedRoute>} />
                <Route path="/owner/billboards" element={<ProtectedRoute requireRole="owner"><OwnerBillboards /></ProtectedRoute>} />
                <Route path="/owner/bookings" element={<ProtectedRoute requireRole="owner"><OwnerBookings /></ProtectedRoute>} />
                <Route path="/owner/payouts" element={<ProtectedRoute requireRole="owner"><OwnerPayouts /></ProtectedRoute>} />
            </Routes>
            {showClientChrome && <Footer />}
        </>
    );
}

export default function App() {
    return (
        <BrowserRouter>
            <AppRoutes />
        </BrowserRouter>
    );
}
