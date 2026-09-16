const KEY = 'token';

/** Storage can throw outright when the browser blocks site data. */
function safely(fn, fallback = null) {
    try {
        return fn();
    } catch {
        return fallback;
    }
}

/** This tab's token, or null when this tab is signed out. */
export function readToken() {
    return safely(() => sessionStorage.getItem(KEY));
}

/** Sign this tab in. No other tab is touched. */
export function writeToken(token) {
    safely(() => sessionStorage.setItem(KEY, token));
}

/** Sign this tab out. No other tab is touched. */
export function clearToken() {
    safely(() => sessionStorage.removeItem(KEY));
}

/**
 * Drop a token left in localStorage by the build that stored it there. Without
 * this, an already-signed-in browser keeps a stale token sitting in shared
 * storage forever - harmless, but it reads as a live session that no tab uses.
 */
export function discardLegacyToken() {
    safely(() => localStorage.removeItem(KEY));
}
