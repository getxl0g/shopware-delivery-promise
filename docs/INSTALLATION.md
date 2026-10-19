# Installation and setup — xl0g Delivery Promise

## What the plugin does

On the product page it shows the **delivery date calculated by xl0g, with a countdown to the order cutoff**, and stores
the date as **JSON-LD** (Schema.org `OfferShippingDetails`) for Google and AI assistants. The numbers come from
the xl0g API; text and language are handled by the plugin per sales channel.

---

## 1 · Create an API key at xl0g

Once, in the xl0g dashboard under **Settings → API keys → “Create new key”**:

| Field | Value |
|---|---|
| **Name** | e.g. `shopware-delivery-promise` |
| **Permission** | `promise:read` — read-only access to the delivery date; the plugin needs nothing more |

Several shops of the same xl0g account can use the same key.

**API base URL:** `https://xl0g.com/<tenant>/api` — `<tenant>` is the short name from the address bar of your
xl0g dashboard, without a trailing slash.

> The key is an access key: treat it like a password, keep it out of Git and public channels. The
> plugin keeps it server-side and never hands it to the browser.

---

## 2 · Install the plugin

**Via Composer (recommended):**

```sh
composer config repositories.xl0g vcs https://github.com/getxl0g/shopware-delivery-promise
composer require xl0g/shopware-delivery-promise:^0.7
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/build-storefront.sh
bin/console cache:clear
```

For shops on Shopware PaaS or platform.sh these steps belong in the build: commit `composer.json` and `composer.lock`,
deploy; `plugin:install` once, then `bin/console plugin:update Xl0gDeliveryPromise` with every update.

**As a ZIP:** upload `Xl0gDeliveryPromise-<version>.zip` from
[Releases](https://github.com/getxl0g/shopware-delivery-promise/releases) in the admin under **Extensions → My
extensions → Upload extension**, install, activate; then build the storefront and clear the cache.

## 3 · Set up per sales channel

Admin → **Settings → Extensions → xl0g Delivery Promise**, choose the **sales channel** at the top:

- **Activation:** “xl0g Delivery Promise on product detail page” and, as you wish, “… in checkout” (both off by default)
- **xl0g API base URL** and **xl0g API key** from step 1
- **Display & behaviour:** shipping method (`standard`), excluded shipping methods (e.g. freight), destination country
  (`DE`), JSON-LD (on), “Report page views to xl0g” as you wish (off)
- optionally additional transit days per delivery country and the countdown thresholds

The **texts** (e.g. “In stock. Delivery by {date}.”) can be adjusted per sales channel under **Settings → Snippets**;
details in [CONFIGURATION.md](CONFIGURATION.md). For the delivery date per cart line item, Shopware’s setting
**Settings → Shop → Cart → “Show delivery time”** must be on.

## 4 · Verify

- **Product page** (product in stock): in the delivery information of the buy box it reads “In stock. Delivery by …”;
  below the countdown threshold also “Order within …”.
- **JSON-LD:** exactly one Product block in the page source, its offer with `OfferShippingDetails` whose `handlingTime`
  and `transitTime` (each minValue = maxValue) add up to the date on the page; with the plugin’s own block or an SEO
  plugin’s markup also `x-xl0g-delivery-date`. Cross-check with Google’s Rich Results Test.
- **Invitation to agents:** load `https://<shop>/llms.txt` and `https://<shop>/.well-known/xl0g-delivery.json`. They
  name only what the shop delivers: no fields with JSON-LD off, no `x-xl0g-*` fields with Shopware’s own JSON-LD.
- **Page views** (if switched on): after a few views, numbers appear in xl0g under “Product Check for AI → Who
  visits?” at “Product pages in the shop”.

If the widget shows nothing, usually the activation switch is off, the URL or key is wrong, the product is not
deliverable right away, or there is (as yet) no date for it — the plugin then shows Shopware’s own delivery time.

## 5 · Before wide use

Check once against the real Shopware instance before all channels are switched over:

1. Does the widget appear in the right place, once? (Twig blocks `component_delivery_information_available` and
   `…_default` of `component/delivery-information.html.twig`, `component_line_item_type_product_delivery_date` for line
   items — a custom theme can override them.)
2. Does the JSON-LD carry real values (name, price, availability)?
3. Do `/.well-known/xl0g-delivery.json` and `/llms.txt` respond?
4. Is Google’s Rich Results Test green?

Recommendation: check one sales channel completely first, then roll the rest over with the same setup.

---

**Architecture:** [ARCHITECTURE.md](ARCHITECTURE.md) · **Settings:** [CONFIGURATION.md](CONFIGURATION.md) ·
**Operations:** [OPERATIONS.md](OPERATIONS.md) · **Development:** [DEVELOPMENT.md](DEVELOPMENT.md)
