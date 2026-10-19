# Development — xl0g Delivery Promise

## Setup

The plugin belongs in `custom/plugins/Xl0gDeliveryPromise` of a Shopware 6.6 or 6.7 installation.

```sh
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/console cache:clear
```

## Building the storefront JS

The JS under `Resources/app/storefront/src` (widget + page view beacon) is shipped compiled in
`Resources/app/storefront/dist/`. After a change, rebuild it in a Shopware installation:

```sh
bin/build-storefront.sh          # once; bin/watch-storefront.sh while developing
```

or standalone with esbuild (`<storefront-src>` = `vendor/shopware/storefront/Resources/app/storefront/src` of a 6.7
installation, `deepmerge` 4.3.1 e.g. via `npm i deepmerge@4.3.1` in a working folder):

```sh
npx esbuild src/Resources/app/storefront/src/main.js \
  --bundle --minify --format=iife --target=es2017 \
  --alias:src=<storefront-src> \
  --alias:deepmerge=<working-folder>/node_modules/deepmerge \
  --outfile=src/Resources/app/storefront/dist/storefront/js/xl0g-delivery-promise/xl0g-delivery-promise.js
```

**Important:** the Twig widget markup and the JS `_render` must stay byte-identical (otherwise the text shifts when
the JS re-renders) — change both together and rebuild the bundle.

## Tests

Pure unit tests (no Shopware kernel needed) for the service layer:

```sh
composer install                 # pulls phpunit/phpunit (require-dev)
vendor/bin/phpunit               # uses phpunit.xml.dist
```

Covered: `PromiseResult` (parsing, country offset, cache lifetime, handling days), `StructuredDataBuilder` (all three
JSON-LD modes), `Xl0gPromiseClient` (cache, negative cache, country offset, page view report), `ShippingMethodResolver`,
`CountryTransitProvider`, `RenderedPromiseCollector`, `HttpCacheCutoffSubscriber`, the Twig extensions,
`VisitorClassifier` and `AgentDiscoveryBuilder`. They run **without a kernel**.

## Structure

Details in [ARCHITECTURE.md](ARCHITECTURE.md). In short:

| Folder | Contents |
|---|---|
| `Service/` | Logic: API client, DTO, shipping method resolver, country offset, JSON-LD builder, visitor classifier, discovery builder. |
| `Storefront/Controller/` | Thin endpoints: promise proxy, page view beacon, discovery (llms.txt / well-known). |
| `Storefront/Subscriber/` | JSON-LD on the product page (+ page view report for agents), HTTP cache capping at the cutoff. |
| `Twig/` | Server-side first render of the widget, merge into Shopware's own JSON-LD. |
| `Resources/` | Config, routes, services, Twig, storefront JS, snippets, logo. |
| `tests/Unit/` | PHPUnit. |

## Conventions

- **xl0g = numbers, shop = language.** No storefront texts in the xl0g backend.
- **API key server-side.** Never in JS/browser.
- **Hide robustly.** Not configured / error / no date → widget & JSON-LD stay away.
- **Additive.** Twig via `sw_extends` + block append, never override a core template.

## Tested against real Shopware

`scripts/compatibility.sh` checks, against the latest release of each supported line (0.7.2 on 2026-10-19: 6.7.15.1
and 6.6.10.29, both with a test access): installation, theme build,
product page with the widget (switched on for the test), the page view beacon route, `/llms.txt`,
`/.well-known/xl0g-delivery.json` and the unit tests. The widget hooks into `component/delivery-information.html.twig`,
which the buy widget includes in 6.6 and 6.7.

With a test access (a key with `promise:read` only), the script also checks real values: set `XL0G_TEST_API_URL` and
`XL0G_TEST_API_KEY` in the environment (never in the repository, never on the command line of a shared machine). The
product page must then carry `x-xl0g-delivery-date` and a `handlingTime` from xl0g in its JSON-LD, and
`/xl0g/delivery-promise` must answer with `promised_date`. The key is handed to the shop on stdin and does not appear in the log.

Still manual:

- Google’s Rich Results Test over a real product page.
- The delivery date per cart line item (needs a cart and the core setting “Show delivery time”).
- Shopware's own JSON-LD (flag `JSON_LD_DATA`) and SEO plugin markup.
- A custom theme may override the delivery information blocks — check the product page once per theme.
