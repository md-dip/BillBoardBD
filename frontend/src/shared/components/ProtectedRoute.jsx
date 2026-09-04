import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import homePathFor from '../utils/homePathFor';

export default function ProtectedRoute({ children, requireRole }) {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return <div style={{ padding: 24, color: '#64748b' }}>Loading…</div>;
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  // Wrong actor for this page - send them to their own app rather than the
  // public home. This is what catches a stale page after an account switch in
  // the same tab: the owner who logs in while a client page is still mounted
  // lands on /owner instead of staring at someone else's bookings.
  if (requireRole && user.role !== requireRole) {
    return <Navigate to={homePathFor(user.role)} replace />;
  }

  return children;
}