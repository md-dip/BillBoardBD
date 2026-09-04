/**
 * Where an actor's app begins. Login, Register and ProtectedRoute all route
 * through this one function so no actor can be left sitting on another one's
 * page - the case that used to break is switching accounts in the same tab
 * without a reload, where the previous actor's page survived the new login.
 */
export default function homePathFor(role) {
    if (role === 'admin') return '/admin';
    if (role === 'owner') return '/owner';

    return '/billboards';
}
