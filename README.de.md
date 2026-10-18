# xl0g Lieferversprechen — Plugin für Shopware 6

[English](README.md) · **Deutsch**

**Der echte Liefertermin auf jeder Produktseite.** Statt „Lieferung in 2–4 Werktagen“ zeigt der Shop den Termin, den
[xl0g](https://xl0g.com) aus Lagerkapazität, Bestellschluss und Laufzeit berechnet — zum Beispiel:

> Geliefert bis **‹Wochentag, Datum›** — bestellen Sie in den nächsten **‹Stunden und Minuten›**.

Datum und Countdown werden bei jedem Seitenaufruf neu berechnet. Dazu legt das Plugin den Termin maschinenlesbar ab,
damit Google und KI-Assistenten wie ChatGPT, Claude oder Perplexity die Frage „Bis wann ist es da?“ richtig beantworten.

## Was das Plugin kann

| Funktion | Was passiert | Schalter |
|---|---|---|
| **Liefertermin auf der Produktseite** | Widget unter dem Kauf-Bereich mit Datum und Countdown bis zum Bestellschluss | immer, sobald verbunden |
| **Liefertermin im Checkout** | dasselbe auf der Bestellabschluss-Seite | „Widget auch im Checkout zeigen“ (aus) |
| **Strukturierte Daten (JSON-LD)** | Schema.org `Product`/`Offer`/`OfferShippingDetails` mit `x-xl0g-delivery-date` und `x-xl0g-cutoff-at` | „JSON-LD auf der Produktseite ausgeben“ (an) |
| **Einladung an KI-Assistenten** | `/llms.txt` und `/.well-known/xl0g-delivery.json` sagen Agenten, wo der Termin steht — und nur das, was der Shop wirklich ausliefert | folgt dem JSON-LD-Schalter |
| **Seitenaufrufe an xl0g melden** (ab 0.7.0) | je Produktseite Artikelnummer und Art des Besuchers (Mensch, KI-Agent, Suchdienst, Werkzeug); in xl0g sichtbar unter „Produkt-Check für KI → Wer kommt?“ | „Seitenaufrufe an xl0g melden“ (aus) |

## Das Prinzip: Zahlen von xl0g, Sprache im Shop

xl0g liefert nur **Zahlen** (`GET /api/promise?format=data`: Liefertermin, Bestellschluss, Laufzeit). Die **Texte** macht
das Plugin — je Verkaufskanal über die Snippets von Shopware. So spricht jeder Kanal seine eigene Sprache, und der
Wortlaut lässt sich im Admin ändern, ohne xl0g anzufassen.

## Datenschutz und Sicherheit

- **Der API-Schlüssel bleibt im Shop.** Der Browser spricht nur mit dem Shop; der Shop fragt xl0g serverseitig.
- **Keine Personendaten an xl0g.** Auch mit eingeschalteter Meldung der Seitenaufrufe gehen nur Artikelnummer, Host des
  Shops und die Art des Besuchers an xl0g. Die Art ordnet das Plugin im Shop ein; User-Agent, IP-Adresse, Cookies,
  Sitzung und Warenkorb verlassen den Shop nie.
- **Fällt xl0g aus, bleibt der Shop heil:** kein Termin, kein Widget — nie ein kaputtes Element, nie ein Fehler beim
  Käufer. Zeitgrenze je Abruf 3 Sekunden.
- **Kein Selbst-Update.** Das Plugin lädt nie Code nach. Updates kommen über Composer oder das Release-ZIP, also über den
  gewohnten Deploy des Shops (Begründung in [docs/OPERATIONS.md](docs/OPERATIONS.md), Abschnitt 5).

## Unterstützte Shopware-Versionen

Das Plugin unterstützt jede Shopware-Linie, die noch Releases vom Hersteller bekommt (zurzeit **6.7** und **6.6**). Fällt
eine Linie aus dem Herstellersupport, lässt die nächste Plugin-Version sie offiziell fallen. Einzelheiten je Version im
[CHANGELOG.md](CHANGELOG.md).

## Installation

**Über Composer (empfohlen)** — Updates kommen dann mit dem normalen Deploy des Shops:

```sh
composer config repositories.xl0g vcs https://github.com/getxl0g/shopware-delivery-promise
composer require xl0g/shopware-delivery-promise:^0.7
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/build-storefront.sh
bin/console cache:clear
```

**Oder als ZIP:** `Xl0gDeliveryPromise-<Version>.zip` von der Seite
[Releases](https://github.com/getxl0g/shopware-delivery-promise/releases) im Admin unter Erweiterungen hochladen,
installieren und aktivieren; danach Storefront bauen und Cache leeren.

Voraussetzungen: eine Shopware-Version aus der Liste oben und ein Zugang zu xl0g mit einem API-Schlüssel mit der
Berechtigung `promise:read` — [xl0g.com](https://xl0g.com). Einrichtung Schritt für Schritt:
[docs/INSTALLATION.md](docs/INSTALLATION.md).

## Dokumentation

- [docs/INSTALLATION.md](docs/INSTALLATION.md) — Installation, API-Schlüssel, Einrichtung je Verkaufskanal, Prüfen
- [docs/CONFIGURATION.md](docs/CONFIGURATION.md) — alle Einstellungen und Texte
- [docs/OPERATIONS.md](docs/OPERATIONS.md) — Betrieb, Überwachung, Sicherheit, Updates
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — Aufbau, Datenfluss
- [docs/UCP_UEBERBLICK.md](docs/UCP_UEBERBLICK.md) — KI-Assistenten und maschinenlesbare Produktdaten für Einsteiger
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — Entwicklung und Tests
- [CHANGELOG.md](CHANGELOG.md) — Versionen

## Tests

```sh
composer install && vendor/bin/phpunit
```

Unit-Tests der Service-Schicht, lauffähig ohne Shopware-Kernel.

## Lizenz

[MIT](LICENSE) © 2026 xl0g. Der Code des Plugins ist offen; der Liefertermin wird bei xl0g berechnet und setzt einen
Zugang voraus.
