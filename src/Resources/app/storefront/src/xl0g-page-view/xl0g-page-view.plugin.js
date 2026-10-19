import Plugin from 'src/plugin-system/plugin.class';

/**
 * xl0g page view beacon (switch “Report page views to xl0g”).
 *
 * Sends ONE `navigator.sendBeacon()` per product page view to the plugin route `frontend.xl0g.page_view` with the
 * product number. The browser does it because the widget itself sends no request while the server-rendered date is
 * valid, and pages from the HTTP cache never reach PHP. The server decides the kind of visitor and counts browsers
 * only. No cookies, no user data: just the product number.
 */
export default class Xl0gPageViewPlugin extends Plugin {
    init() {
        const d = this.el.dataset;
        if (!d.endpoint || !d.sku || typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function') {
            return;
        }
        try {
            navigator.sendBeacon(d.endpoint, new URLSearchParams({ sku: d.sku }));
        } catch (e) {
            // A failed report must never disturb the page.
        }
    }
}
