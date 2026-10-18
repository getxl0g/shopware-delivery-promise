# Architecture — xl0g Delivery Promise (Shopware 6 plugin)

## Guiding principle: xl0g = numbers, shop = language

xl0g delivers the **deterministic numbers** (delivery date, cutoff, transit) in a language-neutral form via
`GET /api/promise?format=data`. **Text + translation is done by this plugin — per Shopware sales channel**,
through the native snippet system. That keeps xl0g a pure data supplier (no storefront translations
in the backend), and the language fits the multilingual shop natively.

## Layers

| Layer | Location | Job |
|---|---|---|
| **Service** | `Service/Xl0gPromiseClient` | Encapsulates config, HTTP call (`X-API-Key`), error handling → `PromiseResult` \| `null` |
| **DTO** | `Service/PromiseResult` | Immutable value object of the numbers (from the xl0g JSON) |
| **Controller** | `Storefront/Controller/DeliveryPromiseController` | Thin proxy: hands the numbers to the storefront JS as JSON (key stays server-side) |
| **Subscriber** | `Storefront/Subscriber/StructuredDataSubscriber` *(B3)* | `shippingDetails` JSON-LD on the PDP (Google/LLMs) |
| **Discovery** | `Storefront/Controller/DiscoveryController` + `Service/AgentDiscoveryBuilder` *(B5)* | `/llms.txt` + `/.well-known/` manifest for AI agents |
| **Storefront** | `Resources/views` + `Resources/app/storefront` | Twig block (placeholder) + JS plugin (fetch numbers, fill snippet template, countdown) |
| **Snippets** | `Resources/snippet/storefront.*.json` | Text templates per language/sales channel (`{date}` / `{countdown}`) |
| **Config** | `Resources/config/config.xml` | Admin configuration (API URL, key; *B4* extends it with mapping + per channel) |

## Data flow (PDP widget)

```
Storefront (PDP)
 └─ Twig block: <div data-xl0g-delivery-promise data-template="{{ snippet }}">
      └─ JS plugin  →  GET /xl0g/delivery-promise            (plugin route)
           └─ DeliveryPromiseController → Xl0gPromiseClient
                └─ GET {apiBaseUrl}/promise?format=data       (header X-API-Key, server-side)
                     └─ xl0g promise engine
      ◄── JSON (numbers)  →  JS fills {date}/{countdown} into the snippet template + live countdown
```

## Robustness

Not configured / error / no date ⇒ `null`/`204` ⇒ the widget stays **hidden**. Never a
broken widget, never a blocked page build (HTTP timeout 3 s, every error is swallowed).

## Security

The **API key stays server-side** (in the `Xl0gPromiseClient`, never in JS/browser). The storefront proxy
only outputs the public delivery numbers.

## Agentic commerce

The plugin occupies the **discovery half** (products + a reliable date, machine-readable) — the
input AI shopping agents need for “when can it be delivered?”. Transaction/payment stays with
Shopware + the maturing protocols (OpenAI/Stripe ACP, Google AP2). *B5* delivers the
agent discovery manifest (`/.well-known/xl0g-delivery.json` + `/llms.txt`).

## Directory structure

```
src/
  Xl0gDeliveryPromise.php              Plugin base class
  Service/
    Xl0gPromiseClient.php              API client (config + HTTP + errors)
    PromiseResult.php                  DTO of the numbers
  Storefront/
    Controller/DeliveryPromiseController.php
    Subscriber/StructuredDataSubscriber.php        (B3)
  Resources/
    config/  services.xml · routes.xml · config.xml · plugin.png · plugin.svg
    views/storefront/…                 Twig extensions
    app/storefront/src/…               Storefront JS plugin
    snippet/storefront.*.json          Translatable texts
tests/                                 PHPUnit (B6)
docs/                                  ARCHITECTURE · CONFIGURATION · DEVELOPMENT
```
