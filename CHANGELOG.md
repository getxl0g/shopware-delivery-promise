# Changelog

Alle nennenswerten Änderungen an diesem Plugin. Format: [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.7.0] — 2026-10-17 — Seitenaufrufe an xl0g melden; Discovery sagt die Wahrheit
- **Neu: „Seitenaufrufe an xl0g melden“** (Plugin-Einstellung `reportPageViews`, **ab Werk aus**). Ist sie an, meldet
  das Plugin je Aufruf einer Produktseite die Artikelnummer, den Host des Shops und die **Art des Besuchers**
  (`browser`, `agent`, `bot`, `werkzeug`; bei KI-Agenten ein Name aus der festen Liste) mit dem ohnehin laufenden Abruf
  `GET /api/promise`. xl0g zeigt es unter „Produkt-Check für KI → Wer kommt?“ (ab xl0g 1.5.297).
- **Der User-Agent verlässt den Shop nie.** `VisitorClassifier` ordnet im Shop ein (Listen wie xl0g
  `internal/botnamen`, Stand 2026-10-17; Googlebot und Applebot zählen als Suchdienst).
- **Jeder Besuch zählt einmal:** Browser meldet das Widget (`sku` geht jetzt mit an den Proxy), Agenten, Suchdienste
  und Werkzeuge meldet der Seiten-Subscriber beim Erzeugen der Seite — sie führen das Widget-JS meist nicht aus. Steht
  JSON-LD aus, macht der Subscriber dafür einen kurzen Abruf (1 s) nur zum Melden. Seiten aus dem HTTP-Cache des Shops
  erreichen PHP nicht: für Agenten ist die Zahl eine Untergrenze.
- Tests: `VisitorClassifierTest` (Einordnung, keine Spur des User-Agents in der Meldung, ungültige Artikelnummern,
  Agentennamen stehen auf der Liste von xl0g).

### Discovery sagt die Wahrheit
- **Discovery sagt jetzt die Wahrheit.** `llms.txt` und `/.well-known/xl0g-delivery.json` nannten die
  Felder `x-xl0g-delivery-date` und `x-xl0g-cutoff-at` **bedingungslos** — auch in Shops, in denen
  `enableJsonLd` aus steht und der `StructuredDataSubscriber` folglich gar kein JSON-LD anfügt. Agenten
  wurden damit auf eine Suche geschickt, die leer ausgeht. Aufgefallen live in einem Kundenshop: llms.txt
  fordert zum Lesen der beiden Felder auf, die Produktseite enthält sie 0×.
- `AgentDiscoveryBuilder::manifest()` und `::llmsTxt()` nehmen den Schalterstand als **Pflichtparameter**
  (kein Default — ein vergessener Aufruf soll auffallen, nicht stillschweigend wieder überversprechen).
  Steht der Schalter aus: `signals: []` und ein Satz, der das benennt, statt Feldnamen.
- Auch mit eingeschaltetem JSON-LD sind die Texte jetzt genau: Artikel ohne Termin (kein Bestand,
  Nachlieferung) tragen bewusst keinen — und `x-xl0g-cutoff-at` steht nur an, solange ein Annahmeschluss
  greift. Beides stand vorher als unbedingte Zusage („on each product detail page") da.
- `DiscoveryController` liest den Sales-Channel wie der `DeliveryPromiseController`
  (`sw-sales-channel-id`) und reicht `isJsonLdEnabled()` durch — zwei Shops auf derselben Instanz
  bekommen damit unterschiedliche, jeweils zutreffende Manifeste.
- Tests: drei neue Fälle in `AgentDiscoveryBuilderTest` (Schalter aus → keine Feldnamen in Manifest und
  llms.txt; Schalter an → die Einschränkungen stehen im Text). Gegenprobe gefahren: gegen die alte
  Fassung schlagen genau diese drei fehl.

## [0.6.1] — 2026-07-10 — Politur
- Storefront-JS: Countdown-Timer wird sauber gestoppt (Cleanup vor Neustart + Stopp, wenn das Element
  aus dem DOM fällt) — kein Timer-Leak bei Re-Renders/Offcanvas.
- `.gitignore` um `.phpunit.cache/` ergänzt (PHPUnit-10-Cache-Verzeichnis).
- Doc-Block der Plugin-Basisklasse: veraltete Phasenliste korrigiert (B3–B6 aktuell).

## [0.6.0] — 2026-07-10 — B6: Tests + Doku + Release-Reife
- **PHPUnit-Unit-Tests** (`tests/Unit/Service/`) für die reinen Funktionen: `PromiseResult::fromArray`,
  `StructuredDataBuilder::build`, `AgentDiscoveryBuilder` — abhängigkeitsfrei, ohne Kernel lauffähig.
  `phpunit.xml.dist` + `require-dev phpunit/phpunit ^10.5` + `autoload-dev`.
- **Doku:** `docs/CONFIGURATION.md` (alle Felder + Snippets pro Sales Channel) + `docs/DEVELOPMENT.md`
  (Setup, Storefront-JS, Tests, Struktur, offene 6.7-Testpunkte). README-Feinschliff (Doku-/Test-Sektion).
- Hinweis: `vendor/bin/phpunit` läuft in Shopware-Dev/CI (lokal hier kein `vendor/`).
- Damit ist das Plugin-Paket **B1–B6 feature-komplett** — offen bleibt nur der Live-Test gegen echte 6.7.

## [0.5.0] — 2026-07-10 — B5: Agent-Discovery-Manifest (agentic commerce)
- **`GET /llms.txt`** + **`GET /.well-known/xl0g-delivery.json`**: zeigen KI-Einkaufsagenten, wo die
  öffentlichen Liefer-Signale liegen (Schema.org-JSON-LD auf den Produktseiten) + verweisen auf die
  Sitemap. Keine Auth nötig — reine öffentliche Discovery-Hinweise.
- **AgentDiscoveryBuilder** (reine, unit-testbare Funktion) baut Manifest + llms.txt aus der Shop-URL.
- Besetzt bewusst die **Discovery-Hälfte** des agentic commerce; Transaktion/Zahlung bleibt Shop +
  reifende Protokolle (OpenAI/Stripe ACP, Google AP2).
- ⚠️ Test gegen echte 6.7: Storefront-Route `/.well-known/xl0g-delivery.json` matcht (kein
  Webserver-/Shopware-Konflikt); `/llms.txt` am Domain-Root erreichbar.

## [0.4.0] — 2026-07-10 — B4: Admin-Config + Checkout-Placement
- **Erweiterte Konfiguration** (Karte „Anzeige & Verhalten"): xl0g-Versandart, Ziel-Land (ISO, fürs
  JSON-LD), JSON-LD an/aus, Checkout-Widget an/aus — alles Sales-Channel-scoped (pro Kanal/Sprache).
- **Config → Verhalten verdrahtet:** `Xl0gPromiseClient` bekommt Config-Getter (`shippingMethod`,
  `shippingCountry`, `isJsonLdEnabled`, `isCheckoutWidgetEnabled`); Subscriber + Controller nutzen sie
  statt fest verdrahtetem „standard"/„DE".
- **Checkout-Placement:** Widget optional auf der Bestellabschluss-Seite (`data-context="checkout"`,
  gleiches JS-Plugin) — per Toggle, Default aus.
- **Per Sales Channel:** Texte kommen aus dem nativen Snippet-System (im Admin unter Einstellungen →
  Snippets pro Sales Channel überschreibbar) — keine Übersetzungen im xl0g-Backend.
- ⚠️ Test gegen echte 6.7: Confirm-Block-Name (`page_checkout_confirm_tos`) verifizieren.

## [0.3.0] — 2026-07-10 — B3: JSON-LD (shippingDetails) + Struktur
- **JSON-LD:** `StructuredDataSubscriber` hängt auf der Produktseite (`ProductPageLoadedEvent`) ein
  Schema.org `Product`/`Offer`/`OfferShippingDetails`-JSON-LD an — Google-Rich-Results + KI-Assistenten.
  `StructuredDataBuilder` (reine, unit-testbare Funktion) baut es aus Shop-Produktdaten + xl0g-Zahlen.
- xl0g-Beitrag im Markup: präzise `shippingDetails` + exaktes Datum (`x-xl0g-delivery-date`) — der
  Verlässlichkeits-Differenzierer, den generische „2–4 Werktage" nicht haben.
- **Struktur:** API-Logik aus dem Controller in `Service/Xl0gPromiseClient` + DTO `PromiseResult`
  gezogen (dünner Controller). xl0g-Logo als `plugin.png`. Neu: `docs/ARCHITECTURE.md`.
- Robust: kein Termin / nicht konfiguriert → keine Extension, kein `<script>` (kein leeres Markup).
- ⚠️ Test gegen echte 6.7: `getTranslation('name')`/`getCalculatedPrice()` gegen die reale
  SalesChannelProductEntity verifizieren; Googles Rich-Results-Test gegenprüfen.

## [0.2.0] — 2026-07-10 — B2: Storefront-Widget (PDP)
- **Storefront-Controller** (`/xl0g/delivery-promise`) als server-seitiger Proxy zur xl0g-Promise-API
  (`?format=data`, Header `X-API-Key`) — der API-Key bleibt server-seitig, nie im Browser.
- **PDP-Twig-Block + JS-Plugin:** holt die Zahlen, formatiert das Datum lokalisiert, füllt das
  **Snippet-Template** (`{date}` / `{countdown}`) und lässt den Countdown gegen `cutoff_at` ticken.
- **Snippets DE/EN** (`storefront.*.json`) — Text pro Sales Channel (B4 baut die Admin-Editierbarkeit aus).
- **Minimal-Config** (API-Basis-URL + API-Key) unter Einstellungen → Plugins.
- Robust: nicht konfiguriert / Fehler / kein Termin → Widget bleibt versteckt (kein kaputtes Widget).
- ⚠️ **Braucht Test gegen echte Shopware 6.7**: Twig-Block-Name verifizieren, Storefront-JS
  bauen (`bin/build-storefront.sh`). Der Termin ist v0 versandart-basiert (per-SKU = Track-A-Folge).

## [0.1.0] — 2026-07-10 — B1: Scaffold
- Lauffähiges Shopware-6-Plugin-Gerüst: `composer.json` (Typ `shopware-platform-plugin`,
  Namespace `Xl0g\DeliveryPromise`), Plugin-Basisklasse, leere `services.xml`, MIT-Lizenz, README.
- Noch KEINE Storefront-Funktion — die kommt mit B2 (Widget) / B3 (JSON-LD) / B4 (Config + Snippets).
- Ziel-Version: **Shopware 6.7** (`shopware/core: ~6.7.0`) — die heute aktuelle Linie (6.7.12.1).
