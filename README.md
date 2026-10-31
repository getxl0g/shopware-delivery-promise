# xl0g Delivery Promise — Shopware 6 plugin

**The real delivery date on every product page.** Instead of “delivery in 2–4 business days”, the shop shows the date
that [xl0g](https://xl0g.com) calculates from warehouse capacity, order cutoff and transit time — for example:

> In stock. Delivery by **‹weekday, date›**. Order within **‹hours and minutes›**.

The date is rendered with the page (no layout shift), the countdown runs in the browser, and every cache expires at the
order cutoff, so the next view shows the next date. The plugin also publishes the date in machine-readable form, so
Google and AI assistants such as ChatGPT, Claude or Perplexity answer “When will it arrive?” correctly.

## Features

| Feature | What happens | Setting |
|---|---|---|
| **Delivery date on the product page** | In the delivery information of the buy box: the date, a countdown below a threshold, a status dot; only for products deliverable right away | “… on product detail page” (off) |
| **Delivery date per cart line item** | offcanvas cart, cart and confirm page | “… in checkout” (off) |
| **Structured data (JSON-LD)** | Exactly one Product block per page: merged into an SEO plugin’s markup or Shopware’s own JSON-LD, otherwise an own block — `handlingTime` and `transitTime` from xl0g | “Emit JSON-LD on the product page” (on) |
| **Shipping methods** | xl0g shipping method per selected shipping method and per product; excluded shipping methods (e.g. freight) keep Shopware’s display | mapping fields, “Excluded shipping methods” |
| **Other delivery countries** | additional transit days per country | “International delivery times” |
| **Invitation to AI assistants** | `/llms.txt` and `/.well-known/xl0g-delivery.json` tell agents where the date is — and only what the shop actually delivers | follows the JSON-LD setting |
| **MCP server on the shop domain** | `POST /mcp`: an AI assistant asks for the delivery date or the product details of a product number and xl0g answers for this shop — no API key needed by the caller; server card under `/.well-known/mcp/server-card.json` | “Offer the xl0g MCP server on this shop” (off) + a key with the `mcp:public` permission |
| **Report page views to xl0g** | per product page the product number and the kind of visitor (person, AI agent, search engine, tool); visible in xl0g under “Product Check for AI → Who visits?” | “Report page views to xl0g” (off) |

## The principle: numbers from xl0g, language in the shop

xl0g delivers **numbers** only (`GET /api/promise?format=data`: delivery date, cutoff, transit time). The plugin writes the
**text** — per sales channel, via Shopware snippets. Every channel speaks its own language, and the wording can be changed
in the admin without touching xl0g.

## Privacy and security

- **The API key stays in the shop.** The browser only talks to the shop; the shop asks xl0g server-side. The same holds
  for the shop MCP: its key is kept server-side, a caller's own key or cookie is never forwarded, and only the two
  public tools pass.
- **No personal data to xl0g.** Even with page-view reporting on, only the product number, the shop host and the kind of
  visitor are sent. The plugin classifies the visitor in the shop; user agent, IP address, cookies, session and cart never
  leave the shop.
- **If xl0g is unavailable, the shop stays intact:** Shopware’s own delivery time instead, never a broken element, never
  an error for the buyer. At most 2 seconds per request, and a failure is cached for 90 seconds, so a disturbance costs at
  most one short wait per 90 seconds, not one per page view.
- **No self-update.** The plugin never downloads code. Updates come via Composer or the release ZIP, i.e. through the
  shop’s normal deployment (reasoning in [docs/OPERATIONS.md](docs/OPERATIONS.md), section 5).

## Supported Shopware versions

The plugin supports every Shopware line that still receives releases from Shopware (currently **6.7** and **6.6**), each
tested with `scripts/compatibility.sh` against its latest release. When a
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
permission — [xl0g.com](https://xl0g.com). Step-by-step setup: [docs/INSTALLATION.md](docs/INSTALLATION.md).

Then switch the widget on per sales channel (it is off by default): **Settings → Extensions → xl0g Delivery Promise**.

## Tests

```sh
composer install && vendor/bin/phpunit
```

Unit tests of the service layer; they run without a Shopware kernel.

## License

[MIT](LICENSE) © 2026 xl0g. The plugin code is open; the delivery date is calculated by xl0g and requires an account.
