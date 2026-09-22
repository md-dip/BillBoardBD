
export default function homePathFor(role) {
    if (role === 'admin') return '/admin';
    if (role === 'owner') return '/owner';

    return '/billboards';
}
