import axios from 'axios';
import { clearToken, readToken } from './tokenStore';

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
    headers: {
        Accept: 'application/json',
    },
});


api.interceptors.request.use((config) => {
    const token = readToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

// If any response comes back 401, the token is invalid/expired - clear it.
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            clearToken();
        }
        return Promise.reject(error);
    }
);

export default api;