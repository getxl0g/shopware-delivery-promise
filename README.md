# xl0g Delivery Promise — Shopware 6 plugin

**English** · [Deutsch](README.de.md)

**The real delivery date on every product page.** Instead of “delivery in 2–4 business days”, the shop shows the date
that [xl0g](https://xl0g.com) calculates from warehouse capacity, order cutoff and transit time — for example:

> Delivered by **‹weekday, date›** — order within the next **‹hours and minutes›**.

The date and the countdown are calculated live on every page view. The plugin also publishes the date in machine-readable
form, so Google and AI assistants such as ChatGPT, Claude or Perplexity answer “When will it arrive?” correctly.

## Features

| Feature | What happens | Setting |
|---|---|---|
| **Delivery date on the product page** | Widget below the buy box with the date and a countdown to the order cutoff | always, once connected |
| **Delivery date in checkout** | the same on the order confirmation page | “Show the widget in checkout too” (off) |
| **Structured data (JSON-LD)** | Schema.org `Product`/`Offer`/`OfferShippingDetails` with `x-xl0g-delivery-date` and `x-xl0g-cutoff-at` | “Emit JSON-LD on the product page” (on) |
| **Invitation to AI assistants** | `/llms.txt` and `/.well-known/xl0g-delivery.json` tell agents where the date is — and only what the shop actually delivers | follows the JSON-LD setting |
| **Report page views to xl0g** (from 0.7.0) | per product page the product number and the kind of visitor (person, AI agent, search engine, tool); visible in xl0g under “Product Check for AI → Who visits?” | “Report page views to xl0g” (off) |

## The principle: numbers from xl0g, language in the shop

xl0g delivers **numbers** only (`GET /api/promise?format=data`: delivery date, cutoff, transit time). The plugin writes the
**text** — per sales channel, via Shopware snippets. Every channel speaks its own language, and the wording can be changed
in the admin without touching xl0g.

## Privacy and security

- **The API key stays in the shop.** The browser only talks to the shop; the shop asks xl0g server-side.
- **No personal data to xl0g.** Even with page-view reporting on, only the product number, the shop host and the kind of
  visitor are sent. The plugin classifies the visitor in the shop; user agent, IP address, cookies, session and cart never
  leave the shop.
- **If xl0g is unavailable, the shop stays intact:** no date, no widget — never a broken element, never an error for the
  buyer. Timeout per request 3 seconds.
- **No self-update.** The plugin never downloads code. Updates come via Composer or the release ZIP, i.e. through the
  shop’s normal deployment (reasoning in [docs/OPERATIONS.md](docs/OPERATIONS.md), section 5).

## Supported Shopware versions

The plugin supports every Shopware line that still receives releases from Shopware (currently **6.7** and **6.6**). When a
line goes out of manufacturer support, the next plugin release drops it officially. Details per version in
[CHANGELOG.md](CHANGELOG.md).

## Installation

**Via Composer (recommended)** — updates then arrive with the shop’s normal deployment:

```sh
composer config repositories.xl0g vcs https://github.com/getxl0g/shopware-delivery-promise
composer require xl0g/shopware-delivery-promise:^0.7
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/build-storefront.sh
bin/console cache:clear
```

**Or as ZIP:** upload `Xl0gDeliveryPromise-<version>.zip` from
[Releases](https://github.com/getxl0g/shopware-delivery-promise/releases) in the admin under Extensions, install and
activate; then build the storefront and clear the cache.

Requirements: a Shopware version from the list above and an xl0g account with an API key holding the `promise:read`
permission — [xl0g.com](https://xl0g.com). Step-by-step setup (German): [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Tests

```sh
composer install && vendor/bin/phpunit
```

Unit tests of the service layer; they run without a Shopware kernel.

## License

[MIT](LICENSE) © 2026 xl0g. The plugin code is open; the delivery date is calculated by xl0g and requires an account.
