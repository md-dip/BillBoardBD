import axios from 'axios';
import { clearToken, readToken } from './tokenStore';

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
    headers: {
        Accept: 'application/json',
    },
});

// NOTE: we deliberately do NOT set a global 'Content-Type' here.
// Axios sets it per request: application/json for plain objects, and
// multipart/form-data (with the right boundary) for FormData. If we forced
// application/json, the campaign creative upload (FormData) would break.

// Attach the Bearer token to every request if this TAB has one saved
// (see tokenStore - each tab holds its own actor).
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