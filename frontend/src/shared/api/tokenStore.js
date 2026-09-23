const KEY = 'token';

/** Storage can throw outright when the browser blocks site data. */
function safely(fn, fallback = null) {
    try {
        return fn();
    } catch {
        return fallback;
    }
}


export function readToken() {
    return safely(() => sessionStorage.getItem(KEY));
}


export function writeToken(token) {
    safely(() => sessionStorage.setItem(KEY, token));
}


export function clearToken() {
    safely(() => sessionStorage.removeItem(KEY));
}

export function discardLegacyToken() {
    safely(() => localStorage.removeItem(KEY));
}
