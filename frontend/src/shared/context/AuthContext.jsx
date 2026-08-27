import { createContext, useContext, useEffect, useState } from 'react';
import api from '../api/axios';

// Create the context — a shared box any component can peek into.
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [token, setToken] = useState(() => localStorage.getItem('token'));
    const [loading, setLoading] = useState(true);

    // On app load: if we have a saved token, verify it's still valid
    // by calling /me. If it works, we get the user. If not, drop the token.
    useEffect(() => {
        if (!token) {
            setLoading(false);
            return;
        }

        api.get('/me')
            .then((res) => setUser(res.data.data))
            .catch(() => {
                localStorage.removeItem('token');
                setToken(null);
            })
            .finally(() => setLoading(false));
    }, [token]);

    async function login(email, password) {
        const res = await api.post('/login', { email, password });
        const { user: loggedInUser, token: newToken } = res.data.data;
        localStorage.setItem('token', newToken);
        setToken(newToken);
        setUser(loggedInUser);
        return loggedInUser;
    }

    async function register(payload) {
        const res = await api.post('/register', payload);
        const { user: newUser, token: newToken } = res.data.data;
        localStorage.setItem('token', newToken);
        setToken(newToken);
        setUser(newUser);
        return newUser;
    }

    async function logout() {
        try {
            await api.post('/logout');
        } finally {
            // Always clear local state even if the server call fails,
            // because otherwise the user gets stuck "logged in" with a dead token.
            localStorage.removeItem('token');
            setToken(null);
            setUser(null);
        }
    }

    return (
        <AuthContext.Provider value={{ user, token, loading, login, register, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

// Small helper so components just write `const { user } = useAuth()`
// instead of importing useContext + AuthContext everywhere.
export function useAuth() {
    return useContext(AuthContext);
}