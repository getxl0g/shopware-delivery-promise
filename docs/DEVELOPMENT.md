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

## Open test points against real 6.7 (before the release)

- Twig blocks: product page `buy_widget_delivery_informations` in `component/buy-widget/buy-widget.html.twig` (Shopware 6.6 and 6.7; the older `page_product_detail_buy_container` is no longer rendered there), checkout `page_checkout_confirm_tos`.
- `getTranslation('name')` / `getCalculatedPrice()` against the real `SalesChannelProductEntity`.
- Routes `/.well-known/xl0g-delivery.json` + `/llms.txt` match (no web server conflict).
- Run Google’s Rich Results Test over a real PDP.
