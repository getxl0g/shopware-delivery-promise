# Configuration — xl0g Delivery Promise

All settings under **Settings → Extensions → xl0g Delivery Promise** — at the top you choose the
**sales channel** the values apply to (each channel/language has its own values).

## Card “Activation”

| Field | Default | Meaning |
|---|---|---|
| **xl0g Delivery Promise on product detail page** | off | Shows the delivery promise on the product page (server-rendered, no layout shift). |
| **xl0g Delivery Promise in checkout** | off | Shows the delivery date per line item in the offcanvas cart, the cart and on the confirm page. |

## Card “Connection”

| Field | Required | Default | Meaning |
|---|---|---|---|
| **xl0g API base URL** | yes | — | Base URL of your xl0g tenant, without a trailing slash, e.g. `https://xl0g.com/<tenant>/api`. |
| **xl0g API key** | yes | — | API key with the `promise:read` permission. Kept **server-side**, never in the browser. |

If either field is missing, the plugin shows **nothing** — no broken widget, no JSON-LD.

## Card “Display & behaviour”

| Field | Default | Meaning |
|---|---|---|
| **xl0g shipping method** | `standard` | Default xl0g shipping method: used on the product page and in the checkout whenever the selected shipping method has no mapping (see “Shipping methods” below). |
| **Excluded shipping methods** | empty | Multi-select of Shopware shipping methods. Products whose product custom field “shipping method” (`shippingMethod`) points to one of them get **no** xl0g delivery promise (details below). |
| **Destination country (ISO, for JSON-LD)** | `DE` | Country code for the `shippingDestination` in the JSON-LD. |
| **Emit JSON-LD on the product page** | on | Schema.org `shippingDetails` for Google rich results and AI agents. Merged into existing product markup: an SEO plugin’s markup (DreiSC SEO Pro) or — with Shopware’s flag `JSON_LD_DATA` (6.7.9+, standard from 6.8) — the core JSON-LD; the plugin emits its own Product block only without either. Also controls what `llms.txt` and `/.well-known/xl0g-delivery.json` announce (see below). |
| **Report page views to xl0g** | off | Reports, per product page view, the product number, shop and kind of visitor (person, AI agent, search engine, tool) to xl0g; visible under “Product Check for AI → Who visits?”. No user agent, no address — see below. |

### What the JSON-LD says, and what the invitation to agents announces

| Situation | Product page | `llms.txt` + manifest |
|---|---|---|
| JSON-LD **on**, own block or SEO plugin markup | `OfferShippingDetails` with `handlingTime` and `transitTime` from xl0g, plus `x-xl0g-delivery-date` and, while an order cutoff applies, `x-xl0g-cutoff-at` | name both fields and add that products without a date deliberately carry none |
| JSON-LD **on**, Shopware’s own JSON-LD (`JSON_LD_DATA`) | `OfferShippingDetails` with `handlingTime` and `transitTime` from xl0g, **no** `x-xl0g-*` fields (not Schema.org vocabulary, the core block stays validator-clean) | name handlingTime + transitTime (minValue = maxValue), no `x-xl0g-*` fields |
| JSON-LD **off** | no JSON-LD from the plugin | name **no** fields, but say that this shop does not provide the dates as structured data |

`handlingTime` is the number of business days until the ship date from xl0g; together with `transitTime` it gives
exactly the date the page shows. A handling time from the Shopware delivery time (e.g. 1–3 days) is replaced; without a
ship date from xl0g it stays.

> **Checking a shop:** `curl -s https://<shop>/.well-known/xl0g-delivery.json` shows under
> `delivery_intelligence.signals` what the shop announces; cross-check on a product page:
> `curl -s <product page URL> | grep -o '"handlingTime":{[^}]*}'`.

### Report page views to xl0g

With the switch on, the shop sees in xl0g **which product pages** are viewed and **by whom** — people, AI agents (by
name: GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot …), search engines or tools.

| What goes to xl0g | What does not |
|---|---|
| product number, shop host, kind of visitor, for AI agents the name from a fixed list | user agent, IP address, cookies, session, cart |

- **Where it is counted:** browsers by a small beacon on every product page (`POST /xl0g/page-view`, one per view,
  also on pages from the HTTP cache); everyone else when the product page is generated. Every visit counts once.
- **Lower bound for agents:** pages the shop serves from its HTTP cache do not reach the plugin.
- **Privacy:** no personal data. Whether the notice is added to the shop’s privacy policy is for the shop operator
  to decide.

## When does the delivery promise appear?

The widget replaces Shopware’s delivery time display only where a product is **deliverable right
away** — the rule is the Shopware core’s availability condition, **without** requiring a Shopware
delivery time on the product (the delivery time comes from xl0g):

| Where | Condition | Fallback without an xl0g date (empty API answer or disturbance) |
|---|---|---|
| **Product page** | product active, no future release date and `stock >= minimum purchase` | Shopware’s delivery time text if one is maintained; otherwise the widget stays hidden (nothing, as in the core) |
| **Cart line items** (offcanvas, cart, confirm page) | `stock >= ordered quantity` | Shopware’s line item delivery date (“Delivery between …”) |

Products that are not deliverable right away keep Shopware’s display: a closeout without stock ⇒ “No
longer available”, stock below the minimum purchase with a restock time **and** a delivery time ⇒
“Ready to ship in X days” (also per line item), otherwise no output.

**Digital products (downloads)** are excluded: xl0g promises a physical delivery date that does not
apply to a download. They keep Shopware’s standard display everywhere.

**Excluded shipping methods:** if the product custom field “shipping method” points to an excluded
shipping method (e.g. freight forwarding or two-person delivery), the plugin is **fully transparent**
for that product — product page, cart line item (including the pre-order line) and JSON-LD behave as if
the plugin were not installed, and the xl0g API is not called. The exclusion applies before the
shipping method mapping and before the fallback chain, so freight goods never get the date of the
standard shipping method by mistake. Products without a value in the custom field are never affected.

> **Cart prerequisite:** Shopware renders the line item delivery time only with the core setting
> **Settings → Shop → Cart → “Show delivery time”** (`core.cart.showDeliveryTime`); without it the xl0g
> widget does not appear either. Order views (order confirmation, customer account) carry no cart data
> any more — they keep the Shopware standard.

## Shipping methods

- **Per shipping method (checkout):** the custom field “xl0g shipping method (mapping)” on a Shopware
  shipping method names its xl0g counterpart (e.g. `express`). The plugin creates the field on install.
  Empty or invalid ⇒ the default from the card above.
- **Per product:** the product custom field `shippingMethod` (entity selection of a shipping method)
  makes the product use that shipping method’s xl0g mapping. If the xl0g API does not know it, the
  widget falls back to the default once — server-side and in the JS.

## Card “International delivery times”

The xl0g API knows no destination country — it returns the date for the base country (DE). For other
delivery countries the plugin adds a configurable number of days to the xl0g date. The delivery
country comes from the visitor’s context: guests = the sales channel’s default country or the choice in
the shipping cost calculator (cart), customers = active delivery address. Changing the country in the
cart or checkout updates the display automatically.

| Field | Default | Meaning |
|---|---|---|
| **Additional transit time per delivery country (days)** | empty | One line per country: `ISO=days`, e.g. `AT=1`. Lines starting with `#` are comments; invalid lines are logged and ignored. **Countries without an entry get the unchanged xl0g date** (as DE). |
| **Skip weekends when adding days** | on | Added days count Mon–Fri only, the result never lands on Sat/Sun (“Thursday +3” ⇒ Tuesday instead of Sunday). |

The offset moves `promised_date` and counts up `transit_days`; **cutoff and countdown stay unchanged**
(the order cutoff is on the warehouse side and does not depend on the country).

> A static offset is an approximation. Once the xl0g API supports a destination country, this mapping
> can go.

## Card “Countdown thresholds”

| Field | Default | Meaning |
|---|---|---|
| **Show countdown below (hours)** | 8 | The sentence “Order within …” appears only once the time left until the cutoff drops below this value. |
| **Minutes only below (hours)** | 1 | Below this value the time left is shown in minutes only (“Order within 38 min.”). Must be lower than the value above. |

## Caching

- The xl0g answer is cached in Shopware’s object cache for up to 5 minutes, failures for 90 seconds
  (a disturbed xl0g API costs at most one short timeout per 90 seconds, not one per page view).
- Every cache layer — object cache, the widget endpoint’s `max-age` and the product page’s `s-maxage` in
  the HTTP cache — expires at the order cutoff at the latest, so the first view after the cutoff shows
  the next date.
- With a valid date in the server-rendered page the widget JS sends no request at all.

## Adjusting texts (per sales channel)

The displayed texts are **Shopware snippets** — overridable per sales channel in the admin under
**Settings → Snippets**, entirely without code:

| Snippet key | Default (EN) | Placeholders |
|---|---|---|
| `xl0g.deliveryPromise.product.line` | “In stock. Delivery by {date}.” | `{date}` |
| `xl0g.deliveryPromise.product.orderWithinHours` | “Order within {hours} hrs {minutes} min.” | `{hours}`, `{minutes}` |
| `xl0g.deliveryPromise.product.orderWithinMinutes` | “Order within {minutes} min.” | `{minutes}` |
| `xl0g.deliveryPromise.checkout.line` | “Delivery by {date}.” | `{date}` |
| `xl0g.deliveryPromise.lineItem.line` | “Delivery by {date}” | `{date}` |

`{date}` is rendered localised (weekday, day and month in the language of the sales channel).
The “within …” part carries a span (`xl0g-dp-within--hours` green, `xl0g-dp-within--minutes` red);
only numbers are inserted. Principle: **the numbers come from xl0g, the text lives here** — so the
language fits your multilingual shop natively. The German defaults ship in `storefront.de-DE.json`.

## Where does the API key come from?

From your xl0g tenant (dashboard → API access). Do you still need access?
→ [xl0g.com](https://xl0g.com)
