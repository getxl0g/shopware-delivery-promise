# Development — xl0g Delivery Promise

## Setup

The plugin belongs in `custom/plugins/Xl0gDeliveryPromise` of a Shopware 6.7 installation.

```sh
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/console cache:clear
```

## Building the storefront JS

The widget JS under `Resources/app/storefront/src` has to be compiled:

```sh
bin/build-storefront.sh          # once; bin/watch-storefront.sh while developing
```

## Tests

Pure unit tests (no Shopware kernel needed) for the service layer:

```sh
composer install                 # pulls phpunit/phpunit (require-dev)
vendor/bin/phpunit               # uses phpunit.xml.dist
```

Covered: `PromiseResult::fromArray`, `StructuredDataBuilder::build`, `AgentDiscoveryBuilder`.
These classes are deliberately kept dependency-free so that they run **without a kernel**.

## Structure

Details in [ARCHITECTURE.md](ARCHITECTURE.md). In short:

| Folder | Contents |
|---|---|
| `Service/` | Logic: API client (`Xl0gPromiseClient`), JSON-LD builder, discovery builder, DTO. Thin, testable. |
| `Storefront/Controller/` | Thin endpoints: promise proxy + discovery (llms.txt / well-known). |
| `Storefront/Subscriber/` | Attaches the JSON-LD to the product page. |
| `Resources/` | Config, routes, services, Twig, storefront JS, snippets, logo. |
| `tests/Unit/` | PHPUnit. |

## Conventions

- **xl0g = numbers, shop = language.** No storefront texts in the xl0g backend.
- **API key server-side.** Never in JS/browser.
- **Hide robustly.** Not configured / error / no date → widget & JSON-LD stay away.
- **Additive.** Twig via `sw_extends` + block append, never override a core template.

## Tested against real Shopware (0.7.1)

`scripts/compatibility.sh` checks, against the latest release of each supported line (2026-10-19: 6.7.15.1 and
6.6.10.29): installation, theme build, product page with the widget placeholder, `/llms.txt`,
`/.well-known/xl0g-delivery.json` and the unit tests. The product page block is
`buy_widget_delivery_informations` in `component/buy-widget/buy-widget.html.twig` (the older
`page_product_detail_buy_container` is no longer rendered since the buy box became a component).

With a test access (a key with `promise:read` only), the script also checks real values: set `XL0G_TEST_API_URL` and
`XL0G_TEST_API_KEY` in the environment (never in the repository, never on the command line of a shared machine). The
product page must then carry `x-xl0g-delivery-date` in its JSON-LD and `/xl0g/delivery-promise` must answer with
`promised_date`. The key is handed to the shop on stdin and does not appear in the log.

Still manual:

- Google’s Rich Results Test over a real product page.
- The checkout widget (block `page_checkout_confirm_tos`, off by default).
- A custom theme may override `buy_widget_delivery_informations` — check the product page once per theme.
