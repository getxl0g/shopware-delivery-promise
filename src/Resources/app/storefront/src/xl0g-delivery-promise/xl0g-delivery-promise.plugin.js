import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

/**
 * xl0g Delivery Promise — storefront widget (B2).
 *
 * Fetches the NUMBERS from the plugin proxy (`frontend.xl0g.delivery_promise` → xl0g /api/promise?format=data),
 * formats the date localised, fills the snippet template ({date} + {countdown}) and ticks the
 * countdown against `cutoff_at`. Principle: xl0g = numbers, this plugin = text/language.
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
        if (d.sku) params.set('sku', d.sku); // 0.7.0: the proxy only reports it if the shop switches reporting on

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
        // 0.7.1 (accessibility): the block is aria-live="polite" and announced once; the countdown ticks every 30 s
        // and is therefore a silent timer (role="timer", aria-live="off") — otherwise a screen reader would keep reading the sentence out.

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
            if (!cd.isConnected) { this._stopCountdown(); return; } // removed from the DOM → no leak
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
