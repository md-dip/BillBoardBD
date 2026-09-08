/**
 * Where the API token lives - the single place, so the axios interceptor and
 * AuthContext can never disagree about who is logged in.
 *
 * The token is kept in sessionStorage, NOT localStorage. localStorage is shared
 * by every tab on the same origin, so signing in as the owner in a second tab
 * would overwrite the admin token the first tab was using and both tabs would
 * silently become the same actor. sessionStorage is scoped to a single tab, so
 * three tabs can hold three different actors at once in one Chrome profile -
 * which is what checking the client, owner and admin views side by side needs.
 *
 * Nothing is copied into a new tab on purpose. Sanctum's logout revokes the one
 * token string it was called with (AuthController::logout), so two tabs holding
 * a COPY of the same token are not independent at all: logging out of either
 * one kills the other. A fresh tab therefore starts signed out and signs in as
 * its own actor, which is also what avoids ever having to log out to switch.
 *
 * The trade-off is deliberate: closing a tab ends that tab's session, and
 * reopening the app asks for a login again.
 */

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
