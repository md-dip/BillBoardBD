import { useEffect } from 'react';

const BRAND = 'BillboardBD';

/**
 * Sets the browser-tab title for the page that calls it. Every routed page
 * owns its own title the same way it owns its own CSS file, so the tab always
 * says which page you are on - and a page whose name is only known at runtime
 * (a billboard, an invoice) can pass it once the data lands and the tab
 * updates with it.
 *
 * Call with no argument on the home page to show the bare brand name.
 */
export default function usePageTitle(title) {
    useEffect(() => {
        document.title = title ? `${title} | ${BRAND}` : BRAND;
    }, [title]);
}
