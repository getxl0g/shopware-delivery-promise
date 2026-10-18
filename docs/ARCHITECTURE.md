# Architektur — xl0g Lieferversprechen (Shopware 6 Plugin)

## Leitprinzip: xl0g = Zahlen, Shop = Sprache

xl0g liefert die **deterministischen Zahlen** (Liefertermin, Cutoff, Transit) sprachneutral über
`GET /api/promise?format=data`. **Text + Übersetzung macht dieses Plugin — pro Shopware Sales Channel**,
über das native Snippet-System. So bleibt xl0g reiner Daten-Lieferant (keine Storefront-Übersetzungen
im Backend), und die Sprache passt nativ zum mehrsprachigen Shop.

## Schichten

| Schicht | Ort | Aufgabe |
|---|---|---|
| **Service** | `Service/Xl0gPromiseClient` | Kapselt Config, HTTP-Aufruf (`X-API-Key`), Fehlerbehandlung → `PromiseResult` \| `null` |
| **DTO** | `Service/PromiseResult` | Unveränderliches Wert-Objekt der Zahlen (aus der xl0g-JSON) |
| **Controller** | `Storefront/Controller/DeliveryPromiseController` | Dünner Proxy: reicht die Zahlen als JSON ans Storefront-JS (Key bleibt server-seitig) |
| **Subscriber** | `Storefront/Subscriber/StructuredDataSubscriber` *(B3)* | `shippingDetails`-JSON-LD auf der PDP (Google/LLMs) |
| **Discovery** | `Storefront/Controller/DiscoveryController` + `Service/AgentDiscoveryBuilder` *(B5)* | `/llms.txt` + `/.well-known/`-Manifest für KI-Agenten |
| **Storefront** | `Resources/views` + `Resources/app/storefront` | Twig-Block (Platzhalter) + JS-Plugin (Zahlen holen, Snippet-Template füllen, Countdown) |
| **Snippets** | `Resources/snippet/storefront.*.json` | Text-Vorlagen pro Sprache/Sales Channel (`{date}` / `{countdown}`) |
| **Config** | `Resources/config/config.xml` | Admin-Konfiguration (API-URL, Key; *B4* erweitert um Mapping + per-Channel) |

## Datenfluss (PDP-Widget)

```
Storefront (PDP)
 └─ Twig-Block: <div data-xl0g-delivery-promise data-template="{{ snippet }}">
      └─ JS-Plugin  →  GET /xl0g/delivery-promise            (Plugin-Route)
           └─ DeliveryPromiseController → Xl0gPromiseClient
                └─ GET {apiBaseUrl}/promise?format=data       (Header X-API-Key, server-seitig)
                     └─ xl0g Promise-Engine
      ◄── JSON (Zahlen)  →  JS füllt {date}/{countdown} ins Snippet-Template + Live-Countdown
```

## Robustheit

Nicht konfiguriert / Fehler / kein Termin ⇒ `null`/`204` ⇒ Widget bleibt **versteckt**. Nie ein
kaputtes Widget, nie ein blockierter Seitenaufbau (HTTP-Timeout 3 s, jeder Fehler wird geschluckt).

## Sicherheit

Der **API-Key bleibt server-seitig** (im `Xl0gPromiseClient`, nie im JS/Browser). Der Storefront-Proxy
gibt nur die öffentlichen Liefer-Zahlen aus.

## Agentic Commerce

Das Plugin besetzt die **Discovery-Hälfte** (Produkte + verlässlicher Termin, maschinenlesbar) — der
Input, den KI-Einkaufsagenten für „bis wann lieferbar?" brauchen. Die Transaktion/Zahlung bleibt
Shopware + die reifenden Protokolle (OpenAI/Stripe ACP, Google AP2). *B5* liefert das
Agent-Discovery-Manifest (`/.well-known/xl0g-delivery.json` + `/llms.txt`).

## Verzeichnisstruktur

```
src/
  Xl0gDeliveryPromise.php              Plugin-Basisklasse
  Service/
    Xl0gPromiseClient.php              API-Client (Config + HTTP + Fehler)
    PromiseResult.php                  DTO der Zahlen
  Storefront/
    Controller/DeliveryPromiseController.php
    Subscriber/StructuredDataSubscriber.php        (B3)
  Resources/
    config/  services.xml · routes.xml · config.xml · plugin.png · plugin.svg
    views/storefront/…                 Twig-Erweiterungen
    app/storefront/src/…               Storefront-JS-Plugin
    snippet/storefront.*.json          Übersetzbare Texte
tests/                                 PHPUnit (B6)
docs/                                  ARCHITECTURE · CONFIGURATION · DEVELOPMENT
```
