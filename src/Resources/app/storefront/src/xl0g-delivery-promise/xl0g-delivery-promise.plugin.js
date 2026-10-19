import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * xl0g Delivery Promise — storefront widget (product page + checkout).
 *
 * Fetches the NUMBERS from the plugin proxy (`frontend.xl0g.delivery_promise` → xl0g /api/promise?format=data),
 * formats the date for the locale and renders the delivery statement. Principle: xl0g = numbers,
 * this plugin = text/language — the sentences arrive as snippets via data attributes.
 *
 * Display logic (thresholds from the plugin config, passed through by the template via
 * data-show-countdown-below-hours / data-minutes-only-below-hours; the constants are only a fallback):
 * - default:                    only the line from data-template ("… Delivery by {date}.")
 * - time left < upper threshold: additionally data-order-within-hours ("Order within {hours} h {minutes} min.")
 * - time left < lower threshold: additionally data-order-within-minutes ("Order within {minutes} min.")
 * The countdown span always stays in the markup (even empty), so the sentence can appear without a
 * refetch when the threshold is crossed; "empty" above the threshold ≠ "cutoff passed"
 * (then: refetch for the next date).
 *
 * The server render wins — no fetch with a valid server date: page HTML and JSON endpoint have
 * separate cache lifetimes (object cache 5 min vs. HTTP cache/Varnish/browser ~1 min) and can drift
 * apart in both directions. A confirming fetch can therefore be OLDER than the freshly rendered
 * markup and would visibly overwrite the new date with the cached one. So: if the server rendered a
 * date whose cutoff has not passed (data-promised-date/-cutoff-at), there is no fetch at all —
 * cached pages show their state until the cutoff passes (then the refetch chain) or the page cache
 * is renewed. A fetch happens only if the server delivered no date (widget hidden/fallback) or the
 * cutoff has already passed (page from an old cache) — then immediately.
 *
 * Cutoff capping on the server (0.16.0): object cache, endpoint max-age and page s-maxage expire at
 * the cutoff at the latest. After the cutoff the first refetch therefore gets fresh data — it comes
 * quickly (5 s + jitter against simultaneous tabs), further ones every 60 s.
 *
 * If the fetch does render (values other than data-promised-date/-cutoff-at), its markup is
 * byte-identical to the Twig markup (the widget templates keep to it) — no layout shift; identical
 * values leave the DOM completely untouched.
 *
 * data-status-indicator: renders the green status dot as well (product page) — on a refetch
 * innerHTML replaces the server-side content including the indicator, so the JS must bring it along.
 */
export default class Xl0gDeliveryPromisePlugin extends Plugin {
    static SHOW_COUNTDOWN_BELOW_HOURS = 8;
    static MINUTES_ONLY_BELOW_HOURS = 1;

    init() {
        this._client = new HttpClient();
        this._cdTimer = null;
        this._retriedAfterCutoff = false;
        const d = this.el.dataset;
        // Server-rendered initial state: let the countdown tick right away from data-cutoff-at.
        if (d.cutoffAt) {
            this._startCountdown(d.cutoffAt);
        }
        // The server render wins (see the class comment): with a valid server date, no fetch — the
        // JSON endpoint can answer from an HTTP/browser cache older than the fresh markup and would
        // visibly overwrite the new date. Fetch only if the server has no date (hidden/fallback) or
        // the cutoff has passed (page from an old cache); an invalid data-cutoff-at counts as passed.
        const cutoffExpired = d.cutoffAt && !(new Date(d.cutoffAt).getTime() > Date.now());
        if (!d.promisedDate || cutoffExpired) {
            this._fetch();
        }
    }

    _fetch(shippingMethod = null) {
        const d = this.el.dataset;
        if (!d.endpoint) return;

        const method = shippingMethod || d.shippingMethod;
        const params = new URLSearchParams();
        if (method) params.set('shipping_method', method);
        if (d.context) params.set('context', d.context);
        if (d.country) params.set('country', d.country); // delivery country → country offset + cache key

        this._client.get(`${d.endpoint}?${params.toString()}`, (responseText) => {
            let data;
            try { data = JSON.parse(responseText); } catch (e) { data = null; }
            if (!data || !data.promised_date) {
                // Empty answer (204): if the API does not know the shipping method (e.g. from the
                // product custom field), exactly ONE refetch with the fallback shipping method —
                // the same chain as on the server. After that: keep the server-rendered state.
                if (d.fallbackShippingMethod && method !== d.fallbackShippingMethod) {
                    this._fetch(d.fallbackShippingMethod);
                }
                return;
            }
            // Answer == already rendered state (above all after the cutoff, while the endpoint
            // caches still deliver the old date): leave the DOM untouched — no layout shift, no
            // aria-live announcement. Still kick the countdown: with a passed cutoff this keeps the
            // refetch chain for the next date alive.
            if (data.promised_date === d.promisedDate && (data.cutoff_at || '') === (d.cutoffAt || '')) {
                this._startCountdown(data.cutoff_at);
                return;
            }
            this._render(data);
        });
    }

    _render(data) {
        const d = this.el.dataset;
        const template = d.template || '{date}';

        const line = template.replace(
            '{date}',
            `<b class="xl0g-dp-date">${this._escape(this._formatDate(data.promised_date))}</b>`
        );
        const indicator = d.statusIndicator ? '<span class="xl0g-status-indicator success"></span>' : '';

        // Keep the markup byte-identical to the widget templates, otherwise the replacement
        // visibly shifts the text (layout shift).
        // The countdown is a silent timer (role="timer", aria-live="off"): the container is aria-live="polite", and
        // without this a screen reader would read the sentence out again whenever the minutes change.
        this.el.innerHTML = `${indicator}${line} <span class="xl0g-dp-countdown" role="timer" aria-live="off"></span>`;
        this.el.hidden = false;
        // Update the basis for comparison for follow-up fetches (after the cutoff).
        d.promisedDate = data.promised_date;
        d.cutoffAt = data.cutoff_at || '';
        this._startCountdown(data.cutoff_at);
    }

    _formatDate(iso) {
        try {
            const dt = new Date(`${iso}T00:00:00`);
            const locale = document.documentElement.lang || undefined;
            return dt.toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'long' });
        } catch (e) {
            return iso;
        }
    }

    _startCountdown(cutoffAt) {
        this._stopCountdown();
        const cd = this.el.querySelector('.xl0g-dp-countdown');
        if (!cd || !cutoffAt) return;

        const update = () => {
            if (!cd.isConnected) { this._stopCountdown(); return; } // removed from the DOM → no leak

            const ms = new Date(cutoffAt).getTime() - Date.now();
            if (Number.isNaN(ms) || ms <= 0) {
                // Cutoff passed: hide the sentence and fetch the next date. The server caches are
                // capped at the cutoff and therefore fresh now — so the FIRST refetch comes quickly
                // (5 s + random jitter: not all open tabs shop-wide at exactly the cutoff second).
                // Further attempts (engine rollover not through yet, same answer ⇒ skip in _fetch)
                // at a calm 60 s rhythm. If the refetch delivers nothing (204), the chain ends on purpose.
                cd.innerHTML = '';
                const delay = this._retriedAfterCutoff
                    ? 60000
                    : 5000 + Math.floor(Math.random() * 15000);
                this._retriedAfterCutoff = true;
                this._cdTimer = window.setTimeout(() => this._fetch(), delay);
                return;
            }
            this._retriedAfterCutoff = false; // countdown running (again) → the next expiry counts as new

            // innerHTML: the snippet sentences contain the highlighting span
            // (xl0g-dp-within--hours/--minutes); only numbers are inserted. Write only on an actual
            // change — leaves the server-rendered sentence in place and spares aria-live
            // announcements without news.
            const sentence = this._countdownSentence(ms);
            if (cd.innerHTML !== sentence) {
                cd.innerHTML = sentence;
            }
            this._cdTimer = window.setTimeout(update, 30000);
        };
        update();
    }

    _countdownSentence(ms) {
        const d = this.el.dataset;
        const totalMinutes = Math.floor(ms / 60000);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;

        const showBelow = parseInt(d.showCountdownBelowHours, 10);
        const minutesOnlyBelow = parseInt(d.minutesOnlyBelowHours, 10);
        const showCountdownBelowHours = Number.isNaN(showBelow) || showBelow <= 0
            ? Xl0gDeliveryPromisePlugin.SHOW_COUNTDOWN_BELOW_HOURS
            : showBelow;
        const minutesOnlyBelowHours = Number.isNaN(minutesOnlyBelow) || minutesOnlyBelow <= 0
            ? Xl0gDeliveryPromisePlugin.MINUTES_ONLY_BELOW_HOURS
            : minutesOnlyBelow;

        if (hours >= showCountdownBelowHours) {
            return '';
        }

        if (hours < minutesOnlyBelowHours) {
            return (d.orderWithinMinutes || '').replace('{minutes}', String(minutes));
        }

        return (d.orderWithinHours || '')
            .replace('{hours}', String(hours))
            .replace('{minutes}', String(minutes));
    }

    _stopCountdown() {
        if (this._cdTimer) {
            window.clearTimeout(this._cdTimer);
            this._cdTimer = null;
        }
    }

    _escape(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
}
