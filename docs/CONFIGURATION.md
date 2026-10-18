# Configuration — xl0g Delivery Promise

All settings under **Settings → Extensions → xl0g Delivery Promise** — at the top you choose the
**sales channel** the values apply to (each channel/language has its own values).

## Card “xl0g Delivery Promise — Connection”

| Field | Required | Default | Meaning |
|---|---|---|---|
| **xl0g API base URL** | yes | — | Base URL of your xl0g tenant, without a trailing slash, e.g. `https://xl0g.com/<tenant>/api`. |
| **xl0g API key** | yes | — | API key with the `promise:read` permission. Kept **server-side**, never in the browser. |

If either field is missing, the plugin shows **nothing** — no broken widget, no JSON-LD.

## Card “Display & behaviour”

| Field | Default | Meaning |
|---|---|---|
| **xl0g shipping method** | `standard` | Which shipping method is queried at xl0g. v0: one representative method (per-SKU follows as a Track A extension). |
| **Destination country (ISO, for JSON-LD)** | `DE` | Country code for the `shippingDestination` in the JSON-LD. |
| **Emit JSON-LD on the product page** | on | Schema.org `shippingDetails` (Google rich results + AI agents). **Also** controls `llms.txt` and `/.well-known/xl0g-delivery.json`: with the switch off, neither announces `x-xl0g-*` fields any more — see below. |
| **Show the widget in checkout too** | off | Shows the delivery promise on the order confirmation page as well. |
| **Report page views to xl0g** (from 0.7.0) | off | Reports, per product page, the product number, shop and kind of visitor (person, AI agent, search engine, tool) to xl0g; visible under “Product Check for AI → Who visits?”. No user agent, no address — see below. |

### The JSON-LD switch works on two levels

The switch is set **per sales channel** (`Xl0gDeliveryPromise.config.enableJsonLd`). It decides
not only whether the product page carries JSON-LD, but also what `llms.txt` and the discovery manifest
**announce** to agents:

| Switch | Product page | `llms.txt` + manifest |
|---|---|---|
| **on** | `Product`/`Offer`/`OfferShippingDetails` with `x-xl0g-delivery-date`, plus `x-xl0g-cutoff-at` while an order cutoff applies | name both fields and add that products without a date deliberately carry none |
| **off** | no JSON-LD from the plugin | name **no** field names, but say that this shop does not provide the dates as structured data |

The two belong together: a manifest that promises fields the shop does not deliver sends
agents on a search that comes up empty — and they then fall back on the range that xl0g replaces.

> **Checking whether the switch is on in a shop:** `curl -s https://<shop>/.well-known/xl0g-delivery.json`
> — if the two `x-xl0g-*` fields are under `delivery_intelligence.signals`, it is on; if the list
> is empty, it is off. Then cross-check on a product page: `curl -s <PDP-URL> | grep -c x-xl0g`.

### Report page views to xl0g (from 0.7.0)

With the switch on, the shop sees in xl0g **which product pages** are viewed and **by whom** — people,
AI agents (by name: GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot …), search engines or tools.

| What goes to xl0g | What does not |
|---|---|
| Product number, shop host, kind of visitor, for AI agents the name from the fixed list | User agent, IP address, cookies, session, cart |

- **Where it is counted:** browsers via the widget (the request runs anyway), everyone else when the product page is
  generated. Every visit counts once.
- **Lower bound for agents:** pages the shop serves from its HTTP cache do not reach the plugin.
- **Privacy:** no personal data. Whether the notice is added to the shop’s privacy policy is for the shop operator
  to decide.

## Adjusting texts (per sales channel)

The displayed texts are **Shopware snippets** — overridable per sales channel in the admin under
**Settings → Snippets**, entirely without code:

| Snippet key | Default (EN) | Placeholders |
|---|---|---|
| `xl0g.deliveryPromise.line` | “Delivered by {date} — order within the next {countdown}.” | `{date}`, `{countdown}` |
| `xl0g.deliveryPromise.unitHours` | “hrs” | — |
| `xl0g.deliveryPromise.unitMinutes` | “min” | — |

`{date}` is rendered localised (weekday, day and month in the language of the sales channel), `{countdown}` ticks live against the
xl0g cutoff. Principle: **the numbers come from xl0g, the text lives here** — so the language fits
your multilingual shop natively. The German defaults ship in `storefront.de-DE.json`.

## Where does the API key come from?

From your xl0g tenant (dashboard → API access). Do you still need access?
→ [xl0g.com](https://xl0g.com)
