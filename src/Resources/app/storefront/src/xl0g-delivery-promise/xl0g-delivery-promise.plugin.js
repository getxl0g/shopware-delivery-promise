import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * xl0g Delivery Promise — Storefront-Widget (B2).
 *
 * Holt die ZAHLEN vom Plugin-Proxy (`frontend.xl0g.delivery_promise` → xl0g /api/promise?format=data),
 * formatiert das Datum lokalisiert, füllt das Snippet-Template ({date} + {countdown}) und lässt den
 * Countdown gegen `cutoff_at` ticken. Prinzip: xl0g = Zahlen, dieses Plugin = Text/Sprache.
 */
export default class Xl0gDeliveryPromisePlugin extends Plugin {
    init() {
        this._client = new HttpClient();
        this._cdTimer = null;
        this._fetch();
    }

    _fetch() {
        const d = this.el.dataset;
        if (!d.endpoint) return;

        const params = new URLSearchParams();
        if (d.shippingMethod) params.set('shipping_method', d.shippingMethod);
        if (d.context) params.set('context', d.context);
        if (d.sku) params.set('sku', d.sku); // 0.7.0: der Proxy meldet sie nur, wenn der Shop es einschaltet

        this._client.get(`${d.endpoint}?${params.toString()}`, (responseText) => {
            let data;
            try { data = JSON.parse(responseText); } catch (e) { return; } // leer/204 → versteckt bleiben
            if (!data || !data.promised_date) return;
            this._render(data);
        });
    }

    _render(data) {
        const d = this.el.dataset;
        const template = d.template || '{date} {countdown}';

        const html = template
            .replace('{date}', `<b class="xl0g-dp-date">${this._escape(this._formatDate(data.promised_date))}</b>`)
            .replace('{countdown}', `<b class="xl0g-dp-countdown" role="timer" aria-live="off">${this._escape(this._formatCountdown(data.cutoff_at))}</b>`);
        // 0.7.1 (Barrierefreiheit): der Block ist aria-live="polite" und wird einmal angesagt; der Countdown tickt alle 30 s
        // und ist deshalb ein stiller Timer (role="timer", aria-live="off") — sonst läse ein Screenreader den Satz ständig vor.

        this.el.innerHTML = html;
        this.el.hidden = false;
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

    _formatCountdown(cutoffAt) {
        if (!cutoffAt) return '';
        const ms = new Date(cutoffAt).getTime() - Date.now();
        if (Number.isNaN(ms) || ms <= 0) return '';
        const h = Math.floor(ms / 3600000);
        const m = Math.floor((ms % 3600000) / 60000);
        const uh = this.el.dataset.unitHours || 'h';
        const um = this.el.dataset.unitMinutes || 'min';
        return `${h} ${uh} ${m} ${um}`;
    }

    _startCountdown(cutoffAt) {
        this._stopCountdown();
        const cd = this.el.querySelector('.xl0g-dp-countdown');
        if (!cd || !cutoffAt) return;
        const update = () => {
            if (!cd.isConnected) { this._stopCountdown(); return; } // aus dem DOM entfernt → kein Leak
            const txt = this._formatCountdown(cutoffAt);
            cd.textContent = txt || '—';
            if (txt) this._cdTimer = window.setTimeout(update, 30000);
        };
        update();
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
