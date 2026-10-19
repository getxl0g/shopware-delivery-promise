# Changelog

All notable changes to this plugin. Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [0.7.2] — 2026-10-19 — Server-rendered widget, cutoff-aware caches, one JSON-LD block per page, shipping methods

- **Server-rendered widget, no layout shift.** The date is rendered with the page from the object cache; the JS only
  runs the countdown. While the server date is valid, the widget sends no request, so a cached endpoint can never
  overwrite a fresh date; identical values leave the DOM untouched.
- **The widget sits in Shopware's delivery information** (`component/delivery-information.html.twig`, included by the
  buy widget in 6.6 and 6.7), also for products without a Shopware delivery time; digital products are excluded.
  Switched on per sales channel: “xl0g Delivery Promise on product detail page” and “… in checkout” (both off by
  default — **upgrade note:** switch them on after updating from 0.7.0 or 0.7.1).
- **Delivery date per cart line item** (offcanvas, cart, confirm page), with a pre-order line for restock items.
- **Resilience:** object cache (success 300 s, failure 90 s), connect timeout 1.5 s, total 2 s, logging channel
  `xl0g_delivery_promise`; every cache layer (object cache, endpoint `max-age`, the page's `s-maxage`) expires at the
  order cutoff, and the first refetch after the cutoff comes after 5 s plus jitter.
- **One JSON-LD Product block per page:** injected into an SEO plugin's markup (DreiSC SEO Pro), merged into Shopware's
  own JSON-LD (flag `JSON_LD_DATA`, without `x-xl0g-*` fields there), or an own block with closeout-aware availability.
- **Handling time from xl0g:** with a ship date from xl0g, the JSON-LD carries `handlingTime` = business days until the
  ship date next to the exact `transitTime` (each minValue = maxValue), in all three modes. A handling time from the
  Shopware delivery time (e.g. 1–3 days) is replaced: before, search engines read a range of several days while the page
  showed one date.
- **Shipping methods:** xl0g shipping method per selected Shopware shipping method (custom field, created on install)
  and per product (product custom field), with a fallback when the API does not know it; **excluded shipping methods**
  (e.g. freight) keep Shopware's display and do not call xl0g.
- **Other delivery countries:** additional transit days per country, weekends skipped.
- **Countdown thresholds** in the config, status dot, highlighted “within …” part; the countdown stays a silent timer
  for screen readers.
- **Report page views to xl0g, rebuilt for the server-rendered widget** (switch `reportPageViews`, off by default):
  browsers are counted by a small beacon on every product page (`POST /xl0g/page-view`, browsers only), agents, search
  engines and tools when the page is generated (separate short request, bypassing the cache). Every visit counts once;
  for agents the number stays a lower bound. The user agent never leaves the shop.
- **Invitation to agents with Shopware's own JSON-LD** names handlingTime + transitTime instead of `x-xl0g-*` fields,
  which the plugin does not write there.
- **Supported Shopware versions:** 6.7 and 6.6, each tested with `scripts/compatibility.sh`; the test switches the
  widget on, checks the beacon route and — with a test access — the handling time in the JSON-LD.

## [0.7.1] — 2026-10-19 — Widget on standard Shopware 6.6/6.7 product pages; Shopware 6.6 supported; English only
- **Fix: the widget and the JSON-LD now appear on a standard Shopware 6.6/6.7 product page.** Since the buy box became
  the `buy-widget` component, the block `page_product_detail_buy_container` of `page/product-detail/index.html.twig` is no
  longer rendered there, so the placeholder and the JSON-LD never showed up on a standard theme. The plugin now hooks into
  `buy_widget_delivery_informations` (right after Shopware's own delivery information). Found by the new compatibility test.
- **Supported Shopware versions: 6.6 and 6.7** (`shopware/core: ~6.6.0 || ~6.7.0`), each tested with
  `scripts/compatibility.sh` against the latest release of the line.
- **Repository in English only** (README, docs, code comments, commits); German stays where the shop shows it
  (configuration labels and snippets `de-DE`). No fixed example sentence with a date any more — a date goes stale.
- **Supported Shopware versions** as a rule: every line that still receives releases from Shopware; when a line goes
  out of manufacturer support, the next plugin version officially drops it.
- **Accessibility:** the countdown is a silent timer (`role="timer"`, `aria-live="off"`) — before, a screen reader read
  out the whole sentence again every 30 seconds.
- **`scripts/compatibility.sh <version>`:** test against a real Shopware instance in Docker (installation, theme,
  product page with widget, invitation to agents, unit tests).
- **`scripts/check-public.sh`:** before every push, no keys, passwords, tokens or `.env` files in the
  repository; optionally a local list of forbidden words.

## [0.7.0] — 2026-10-17 — Report page views to xl0g; discovery tells the truth
- **New: “Report page views to xl0g”** (plugin setting `reportPageViews`, **off by default**). When on, the plugin
  reports, for each product page view, the product number, the shop host and the **kind of visitor**
  (`browser`, `agent`, `bot`, `werkzeug` = tool; for AI agents a name from a fixed list) along with the request that
  runs anyway, `GET /api/promise`. xl0g shows it under “Product Check for AI → Who visits?” (from xl0g 1.5.297).
- **The user agent never leaves the shop.** `VisitorClassifier` classifies in the shop (lists like xl0g
  `internal/botnamen`, as of 2026-10-17; Googlebot and Applebot count as search engines).
- **Every visit counts once:** browsers are reported by the widget (`sku` is now sent to the proxy as well); agents,
  search engines and tools are reported by the page subscriber when the page is generated — they mostly do not run the
  widget JS. If JSON-LD is off, the subscriber makes a short request (1 s) just for reporting. Pages served from the
  shop’s HTTP cache do not reach PHP: for agents the number is a lower bound.
- Tests: `VisitorClassifierTest` (classification, no trace of the user agent in the report, invalid product numbers,
  agent names are on xl0g’s list).

### Discovery tells the truth
- **Discovery now tells the truth.** `llms.txt` and `/.well-known/xl0g-delivery.json` named the fields
  `x-xl0g-delivery-date` and `x-xl0g-cutoff-at` **unconditionally** — even in shops where `enableJsonLd` is off and the
  `StructuredDataSubscriber` therefore adds no JSON-LD at all. Agents were sent on a search that comes up empty.
  Noticed live in a customer shop: llms.txt asks to read the two fields, the product page contains them 0 times.
- `AgentDiscoveryBuilder::manifest()` and `::llmsTxt()` take the switch state as a **required parameter**
  (no default — a forgotten call should be noticed, not silently over-promise again).
  With the switch off: `signals: []` and a sentence that says so, instead of field names.
- Even with JSON-LD on, the texts are now exact: products without a date (out of stock, backorder) deliberately carry
  none — and `x-xl0g-cutoff-at` is only present while an order cutoff applies. Both used to read as an unconditional
  promise (“on each product detail page”).
- `DiscoveryController` reads the sales channel like the `DeliveryPromiseController`
  (`sw-sales-channel-id`) and passes `isJsonLdEnabled()` through — two shops on the same instance
  therefore get different manifests, each accurate.
- Tests: three new cases in `AgentDiscoveryBuilderTest` (switch off → no field names in manifest and
  llms.txt; switch on → the restrictions are in the text). Counter-test run: against the old
  version exactly these three fail.

## [0.6.1] — 2026-07-10 — Polish
- Storefront JS: the countdown timer is stopped cleanly (cleanup before restart + stop when the element
  falls out of the DOM) — no timer leak on re-renders/offcanvas.
- `.gitignore` extended with `.phpunit.cache/` (PHPUnit 10 cache directory).
- Doc block of the plugin base class: outdated phase list corrected (B3–B6 current).

## [0.6.0] — 2026-07-10 — B6: tests + docs + release readiness
- **PHPUnit unit tests** (`tests/Unit/Service/`) for the pure functions: `PromiseResult::fromArray`,
  `StructuredDataBuilder::build`, `AgentDiscoveryBuilder` — dependency-free, runnable without a kernel.
  `phpunit.xml.dist` + `require-dev phpunit/phpunit ^10.5` + `autoload-dev`.
- **Docs:** `docs/CONFIGURATION.md` (all fields + snippets per sales channel) + `docs/DEVELOPMENT.md`
  (setup, storefront JS, tests, structure, open 6.7 test points). README polish (docs/tests section).
- Note: `vendor/bin/phpunit` runs in Shopware dev/CI (no `vendor/` locally here).
- With that the plugin package is **B1–B6 feature-complete** — only the live test against a real 6.7 remains open.

## [0.5.0] — 2026-07-10 — B5: agent discovery manifest (agentic commerce)
- **`GET /llms.txt`** + **`GET /.well-known/xl0g-delivery.json`**: show AI shopping agents where the
  public delivery signals are (Schema.org JSON-LD on the product pages) + point to the
  sitemap. No auth needed — purely public discovery hints.
- **AgentDiscoveryBuilder** (pure, unit-testable function) builds manifest + llms.txt from the shop URL.
- Deliberately occupies the **discovery half** of agentic commerce; transaction/payment stays with the shop +
  maturing protocols (OpenAI/Stripe ACP, Google AP2).
- ⚠️ Test against real 6.7: the storefront route `/.well-known/xl0g-delivery.json` matches (no
  web server/Shopware conflict); `/llms.txt` reachable at the domain root.

## [0.4.0] — 2026-07-10 — B4: admin config + checkout placement
- **Extended configuration** (card “Display & behaviour”): xl0g shipping method, destination country (ISO, for
  JSON-LD), JSON-LD on/off, checkout widget on/off — all sales-channel scoped (per channel/language).
- **Config → behaviour wired up:** `Xl0gPromiseClient` gets config getters (`shippingMethod`,
  `shippingCountry`, `isJsonLdEnabled`, `isCheckoutWidgetEnabled`); subscriber + controller use them
  instead of a hard-wired “standard”/“DE”.
- **Checkout placement:** widget optionally on the order confirmation page (`data-context="checkout"`,
  same JS plugin) — via toggle, default off.
- **Per sales channel:** texts come from the native snippet system (overridable per sales channel in the admin under
  Settings → Snippets) — no translations in the xl0g backend.
- ⚠️ Test against real 6.7: verify the confirm block name (`page_checkout_confirm_tos`).

## [0.3.0] — 2026-07-10 — B3: JSON-LD (shippingDetails) + structure
- **JSON-LD:** `StructuredDataSubscriber` attaches a Schema.org `Product`/`Offer`/`OfferShippingDetails` JSON-LD to the
  product page (`ProductPageLoadedEvent`) — Google rich results + AI assistants.
  `StructuredDataBuilder` (pure, unit-testable function) builds it from shop product data + xl0g numbers.
- xl0g’s contribution to the markup: precise `shippingDetails` + exact date (`x-xl0g-delivery-date`) — the
  reliability differentiator that generic “2–4 business days” lack.
- **Structure:** API logic moved out of the controller into `Service/Xl0gPromiseClient` + DTO `PromiseResult`
  (thin controller). xl0g logo as `plugin.png`. New: `docs/ARCHITECTURE.md`.
- Robust: no date / not configured → no extension, no `<script>` (no empty markup).
- ⚠️ Test against real 6.7: verify `getTranslation('name')`/`getCalculatedPrice()` against the real
  SalesChannelProductEntity; cross-check with Google’s Rich Results Test.

## [0.2.0] — 2026-07-10 — B2: storefront widget (PDP)
- **Storefront controller** (`/xl0g/delivery-promise`) as a server-side proxy to the xl0g promise API
  (`?format=data`, header `X-API-Key`) — the API key stays server-side, never in the browser.
- **PDP Twig block + JS plugin:** fetches the numbers, formats the date localised, fills the
  **snippet template** (`{date}` / `{countdown}`) and ticks the countdown against `cutoff_at`.
- **Snippets DE/EN** (`storefront.*.json`) — text per sales channel (B4 builds out admin editability).
- **Minimal config** (API base URL + API key) under Settings → Plugins.
- Robust: not configured / error / no date → widget stays hidden (no broken widget).
- ⚠️ **Needs a test against real Shopware 6.7**: verify the Twig block name, build the storefront JS
  (`bin/build-storefront.sh`). The date is v0 shipping-method based (per-SKU = Track A follow-up).

## [0.1.0] — 2026-07-10 — B1: scaffold
- Runnable Shopware 6 plugin skeleton: `composer.json` (type `shopware-platform-plugin`,
  namespace `Xl0g\DeliveryPromise`), plugin base class, empty `services.xml`, MIT licence, README.
- Still NO storefront function — that comes with B2 (widget) / B3 (JSON-LD) / B4 (config + snippets).
- Target version: **Shopware 6.7** (`shopware/core: ~6.7.0`) — the current line today (6.7.12.1).
