# Entwicklung — xl0g Lieferversprechen

## Setup

Das Plugin gehört nach `custom/plugins/Xl0gDeliveryPromise` einer Shopware-6.7-Installation.

```sh
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/console cache:clear
```

## Storefront-JS bauen

Das Widget-JS unter `Resources/app/storefront/src` muss kompiliert werden:

```sh
bin/build-storefront.sh          # einmalig; bin/watch-storefront.sh beim Entwickeln
```

## Tests

Reine Unit-Tests (kein Shopware-Kernel nötig) für die Service-Schicht:

```sh
composer install                 # zieht phpunit/phpunit (require-dev)
vendor/bin/phpunit               # nutzt phpunit.xml.dist
```

Abgedeckt: `PromiseResult::fromArray`, `StructuredDataBuilder::build`, `AgentDiscoveryBuilder`.
Diese Klassen sind bewusst abhängigkeitsfrei gehalten, damit sie **ohne Kernel** laufen.

## Struktur

Details in [ARCHITECTURE.md](ARCHITECTURE.md). Kurz:

| Ordner | Inhalt |
|---|---|
| `Service/` | Logik: API-Client (`Xl0gPromiseClient`), JSON-LD-Builder, Discovery-Builder, DTO. Dünn, testbar. |
| `Storefront/Controller/` | Dünne Endpunkte: Promise-Proxy + Discovery (llms.txt / well-known). |
| `Storefront/Subscriber/` | Hängt das JSON-LD an die Produktseite. |
| `Resources/` | Config, Routes, Services, Twig, Storefront-JS, Snippets, Logo. |
| `tests/Unit/` | PHPUnit. |

## Konventionen

- **xl0g = Zahlen, Shop = Sprache.** Keine Storefront-Texte im xl0g-Backend.
- **API-Key server-seitig.** Nie im JS/Browser.
- **Robust ausblenden.** Nicht konfiguriert / Fehler / kein Termin → Widget & JSON-LD bleiben weg.
- **Additiv.** Twig via `sw_extends` + Block-Append, nie ein Kern-Template überschreiben.

## Offene Testpunkte gegen echte 6.7 (vor dem Release)

- Twig-Block-Namen verifizieren (PDP: `page_product_detail_buy_container`, Checkout: `page_checkout_confirm_tos`).
- `getTranslation('name')` / `getCalculatedPrice()` gegen die reale `SalesChannelProductEntity`.
- Routen `/.well-known/xl0g-delivery.json` + `/llms.txt` matchen (kein Webserver-Konflikt).
- Googles Rich-Results-Test über eine echte PDP laufen lassen.
