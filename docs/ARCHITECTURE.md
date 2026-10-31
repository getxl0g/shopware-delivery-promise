# Architecture — xl0g Delivery Promise (Shopware 6 plugin)

## Guiding principle: xl0g = numbers, shop = language

xl0g delivers the **deterministic numbers** (delivery date, cutoff, transit) in a language-neutral form via
`GET /api/promise?format=data`. **Text + translation is done by this plugin — per Shopware sales channel**,
through the native snippet system. That keeps xl0g a pure data supplier (no storefront translations
in the backend), and the language fits the multilingual shop natively.

## Layers

| Layer | Location | Job |
|---|---|---|
| **Client** | `Service/Xl0gPromiseClient` | Config, HTTP call (`X-API-Key`), object cache with negative cache, country offset → `PromiseResult` \| `null` |
| **DTO** | `Service/PromiseResult` | Immutable value object of the numbers; cache lifetime up to the cutoff (`ttlUntilCutoff()`) |
| **Shipping methods** | `Service/ShippingMethodResolver` | xl0g shipping method per product, per selected shipping method, excluded shipping methods |
| **Country offset** | `Service/CountryTransitProvider` | Additional transit days per delivery country (config mapping) |
| **Twig** | `Twig/DeliveryPromiseTwigExtension` | Server-side first render of the widget (date from the object cache) |
| **Twig** | `Twig/StructuredDataTwigExtension` | Merges the shippingDetails into Shopware’s own product JSON-LD |
| **JSON-LD** | `Storefront/Subscriber/StructuredDataSubscriber` + `Service/StructuredDataBuilder` | One Product block per page: inject into SEO plugin markup, into the core JSON-LD, or own fallback block |
| **HTTP cache** | `Service/RenderedPromiseCollector` + `Storefront/Subscriber/HttpCacheCutoffSubscriber` | Caps the product page’s `s-maxage` at the cutoff of the rendered date |
| **Proxy** | `Storefront/Controller/DeliveryPromiseController` | Thin JSON proxy for the widget JS (key stays server-side), `max-age` capped at the cutoff |
| **Discovery** | `Storefront/Controller/DiscoveryController` + `Service/AgentDiscoveryBuilder` | `/llms.txt` + `/.well-known/xl0g-delivery.json` for AI agents — announce only what the shop delivers |
| **Shop MCP** | `Storefront/Controller/McpController` + `Service/ShopMcpProxy` | `POST /mcp` forwarded to the tenant's xl0g MCP server with the shop-side key (two public tools, caller secrets never travel, per-address limit) + `/.well-known/mcp/server-card.json` |
| **Storefront** | `Resources/views` + `Resources/app/storefront` | Twig overrides + JS plugin (countdown, refetch after the cutoff) |
| **Snippets** | `Resources/snippet/storefront.*.json` | Text templates per language/sales channel |
| **Config** | `Resources/config/config.xml` | Admin configuration per sales channel |

## Data flow (product page widget)

```
Storefront (product page)
 └─ Twig: <div data-xl0g-delivery-promise data-promised-date data-cutoff-at …>
      │   The date is rendered server-side (Twig extension → Xl0gPromiseClient → object cache);
      │   the JS only starts the live countdown from data-cutoff-at. The server render wins: as long
      │   as the cutoff has not passed, the JS sends NO request (page HTML and JSON endpoint have
      │   separate cache lifetimes — an endpoint fetch could be older than the markup and visibly
      │   overwrite the fresh date).
      └─ only without a server date (widget hidden/fallback) or after the cutoff:
           JS plugin  →  GET /xl0g/delivery-promise            (plugin route)
                └─ DeliveryPromiseController → Xl0gPromiseClient
                     └─ GET {apiBaseUrl}/promise?format=data    (header X-API-Key, server-side)
                          └─ xl0g promise engine
           ◄── JSON (numbers)  →  JS renders byte-identically to the Twig markup; identical values
                                 leave the DOM untouched (no layout shift)
```

### Cutoff-aware caches

`cutoff_at` is the moment, known in advance, at which the date definitely changes — so everywhere
**cache expiry = cutoff** (`PromiseResult::ttlUntilCutoff()`, only shortening, with lower bounds as
API/stampede protection):

| Layer | TTL (capped at the cutoff) | Where |
|---|---|---|
| Object cache (numbers for server render, endpoint and JSON-LD) | ≤ 300 s, min. 5 s; answer with a passed cutoff ⇒ 30 s; failure ⇒ 90 s | `Xl0gPromiseClient` |
| Endpoint JSON (Varnish + browser) | ≤ 60 s, min. 5 s; 204 ⇒ 30 s | `DeliveryPromiseController` |
| Page HTTP cache (product page HTML) | ≤ Shopware default (~2 h), min. 5 s | `RenderedPromiseCollector` + `HttpCacheCutoffSubscriber` (kernel.response, −1600) |

Everything expires at the cutoff at the latest; the first access afterwards necessarily renders fresh.
After the cutoff the JS refetches quickly (5 s + 0–15 s jitter against simultaneous tabs), then every
60 s. Changes WITHOUT a cutoff change deliberately stay until the regular cache expiry (no visible flip).

## Availability switch (storefront)

Shopware’s `component/delivery-information.html.twig` decides in an if/elseif chain **before** the
plugin’s blocks which branch renders; the “available” branch additionally requires a maintained Shopware
delivery time. The plugin stays additive (`sw_extends` + `parent()`, no core template replaced) and hooks
the product page widget into two blocks:

| Block | When | Fallback in the widget |
|---|---|---|
| `component_delivery_information_available` | core condition met (available **and** delivery time) | Shopware’s delivery time text (`parent()`) |
| `component_delivery_information_default` (else branch) | no Shopware delivery time, but `stock >= minPurchase` — the same core condition without the delivery time part | output of the predecessors in the template stack (core: empty ⇒ widget `hidden`; Commercial ProductBundles: delivery time range) |

`component/delivery-information.html.twig` is included by the buy widget in Shopware 6.6 and 6.7, also
inside the CMS buy box element. Digital products are excluded in both blocks. Cart line items
(`component_line_item_type_product_delivery_date`, only with `core.cart.showDeliveryTime`) use the same rule
on the `DeliveryInformation` struct (`stock >= quantity`) — no additional queries; order views
(`OrderLineItemEntity`) keep the Shopware standard.

## JSON-LD: one Product block per page

| Situation | What the plugin does | `x-xl0g-*` fields |
|---|---|---|
| SEO plugin markup present (DreiSC SEO Pro extension `dreiscSeoInstallmentRichSnippetData`, duck-typed, subscriber priority −10) | injects transitTime and a missing shippingDestination into its offers; `availability` stays the SEO plugin’s | on the Product node |
| Shopware core JSON-LD (flag `JSON_LD_DATA`, 6.7.9+, standard from 6.8) | template override merges the shippingDetails into the core node (`xl0g_inject_shipping_details`) | none — not Schema.org vocabulary, the core block stays validator-clean |
| neither | own Product block, closeout-aware availability | on the Product node |

## International delivery addresses

The xl0g API knows no destination country. The `CountryTransitProvider` reads a config mapping
(country ⇒ additional days, sales-channel scoped); the `Xl0gPromiseClient` adds the offset **after** the
object cache (cache and API call stay country-neutral — one request serves all countries). The delivery
country comes from the `SalesChannelContext` and travels as `data-country`/`country` query parameter
through widget and proxy (HTTP cache per country). Cutoff and countdown never change (warehouse side).

## Robustness

Not configured / error / no date ⇒ `null`/`204` ⇒ the widget shows the fallback or stays **hidden**.
Never a broken widget, never a blocked page build (connect timeout 1.5 s, total 2 s, every error is
swallowed and logged on the channel `xl0g_delivery_promise`, throttled by the negative cache).

## Security

The **API key stays server-side** (in the `Xl0gPromiseClient`, never in JS/browser). The storefront proxy
only outputs the public delivery numbers; `context`, `shipping_method` and `country` are validated before
they reach a cache key.

## Agentic commerce

The plugin occupies the **discovery half** (products + a reliable date, machine-readable) — the
input AI shopping agents need for “when can it be delivered?”. Transaction/payment stays with
Shopware + the maturing protocols (OpenAI/Stripe ACP, Google AP2). The agent discovery manifest
(`/.well-known/xl0g-delivery.json` + `/llms.txt`) names only fields the shop actually delivers.

## Directory structure

```
src/
  Xl0gDeliveryPromise.php              plugin base class (creates the shipping method mapping field)
  Service/                             client, DTO, resolver, country offset, JSON-LD builder, collector
  Storefront/
    Controller/                        DeliveryPromiseController · DiscoveryController · McpController
    Subscriber/                        StructuredDataSubscriber · HttpCacheCutoffSubscriber
  Twig/                                DeliveryPromiseTwigExtension · StructuredDataTwigExtension
  Resources/
    config/  services.xml · routes.xml · config.xml · plugin.png · plugin.svg
    views/storefront/…                 Twig overrides (delivery information, line item, core JSON-LD)
    app/storefront/src/…               storefront JS plugin + SCSS (dist/ holds the built bundle)
    snippet/storefront.*.json          translatable texts
tests/                                 PHPUnit (no Shopware kernel needed)
docs/                                  ARCHITECTURE · CONFIGURATION · DEVELOPMENT · INSTALLATION · OPERATIONS
```
