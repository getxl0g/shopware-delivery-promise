# Installation und Einrichtung — xl0g Lieferversprechen

## Was das Plugin tut

Es zeigt auf der Produktseite den von xl0g berechneten **Liefertermin mit Countdown bis zum Bestellschluss** und legt
den Termin als **JSON-LD** (Schema.org `OfferShippingDetails`) für Google und KI-Assistenten ab. Die Zahlen kommen aus
der xl0g-API, Text und Sprache macht das Plugin je Verkaufskanal.

---

## 1 · API-Schlüssel bei xl0g anlegen

Einmalig im xl0g-Dashboard unter **Einstellungen → API-Schlüssel → „Neuen Schlüssel anlegen“**:

| Feld | Wert |
|---|---|
| **Name** | z. B. `shopware-lieferversprechen` |
| **Berechtigung** | `promise:read` — nur Lesen des Liefertermins; mehr braucht das Plugin nicht |

Mehrere Shops desselben xl0g-Zugangs können denselben Schlüssel verwenden.

**API-Basis-URL:** `https://xl0g.com/<mandant>/api` — `<mandant>` ist das Kürzel aus der Adresszeile eures
xl0g-Dashboards, ohne abschließenden Schrägstrich.

> Der Schlüssel ist ein Zugangsschlüssel: wie ein Passwort behandeln, nicht ins Git, nicht in öffentliche Kanäle. Das
> Plugin hält ihn serverseitig und gibt ihn nie an den Browser.

---

## 2 · Plugin installieren

**Über Composer (empfohlen):**

```sh
composer config repositories.xl0g vcs https://github.com/getxl0g/shopware-delivery-promise
composer require xl0g/shopware-delivery-promise:^0.7
bin/console plugin:refresh
bin/console plugin:install --activate Xl0gDeliveryPromise
bin/build-storefront.sh
bin/console cache:clear
```

Bei Shops auf Shopware PaaS oder platform.sh gehören diese Schritte in den Build: `composer.json` und `composer.lock`
committen, deployen; `plugin:install` einmalig, danach bei jedem Update `bin/console plugin:update Xl0gDeliveryPromise`.

**Als ZIP:** `Xl0gDeliveryPromise-<Version>.zip` von
[Releases](https://github.com/getxl0g/shopware-delivery-promise/releases) im Admin unter **Erweiterungen → Meine
Erweiterungen → Erweiterung hochladen** hochladen, installieren, aktivieren; danach Storefront bauen und Cache leeren.

## 3 · Je Verkaufskanal einrichten

Admin → **Einstellungen → Erweiterungen → xl0g Lieferversprechen**, oben den **Verkaufskanal** wählen:

- **xl0g API-Basis-URL** und **xl0g API-Schlüssel** aus Schritt 1
- **Anzeige & Verhalten:** Versandart (`standard`), Ziel-Land (`DE`), JSON-LD (an), Checkout-Widget nach Wunsch,
  „Seitenaufrufe an xl0g melden“ nach Wunsch (aus)

Die **Texte** (z. B. „Geliefert bis {date} — bestellen Sie in den nächsten {countdown}.“) sind je Verkaufskanal unter
**Einstellungen → Snippets** anpassbar; Details in [CONFIGURATION.md](CONFIGURATION.md).

## 4 · Prüfen

- **Produktseite:** unter dem Kauf-Bereich steht „Geliefert bis … — bestellen Sie in den nächsten …“.
- **JSON-LD:** im Seitenquelltext ein `<script type="application/ld+json">` mit `OfferShippingDetails` und
  `x-xl0g-delivery-date`; mit dem Rich-Results-Test von Google gegenprüfen.
- **Einladung an Agenten:** `https://<shop>/llms.txt` und `https://<shop>/.well-known/xl0g-delivery.json` laden. Die
  Felder `x-xl0g-*` stehen dort nur, wenn JSON-LD eingeschaltet ist.
- **Seitenaufrufe** (falls eingeschaltet): nach einigen Aufrufen stehen in xl0g unter „Produkt-Check für KI → Wer
  kommt?“ Zahlen bei „Produktseiten im Shop“.

Zeigt das Widget nichts, stimmen meist URL oder Schlüssel nicht, oder für den Artikel liegt (noch) kein Termin vor —
das Plugin blendet sich dann bewusst aus.

## 5 · Vor dem breiten Einsatz

Gegen die echte Shopware-Instanz einmal prüfen, bevor alle Kanäle umgestellt werden:

1. Erscheint das Widget an der richtigen Stelle? (Twig-Blöcke `page_product_detail_buy_container`,
   `page_checkout_confirm_tos` — ein eigenes Theme kann sie überschreiben.)
2. Trägt das JSON-LD echte Werte (Name, Preis, Verfügbarkeit)?
3. Antworten `/.well-known/xl0g-delivery.json` und `/llms.txt`?
4. Ist der Rich-Results-Test von Google grün?

Empfehlung: erst einen Verkaufskanal vollständig prüfen, dann die übrigen mit derselben Einrichtung.

---

**Aufbau:** [ARCHITECTURE.md](ARCHITECTURE.md) · **Einstellungen:** [CONFIGURATION.md](CONFIGURATION.md) ·
**Betrieb:** [OPERATIONS.md](OPERATIONS.md) · **Entwicklung:** [DEVELOPMENT.md](DEVELOPMENT.md)
