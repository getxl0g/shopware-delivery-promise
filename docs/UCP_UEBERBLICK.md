# UCP & Agentic Commerce — Überblick für Einsteiger

Für Personen, die mit „UCP" **noch nie** zu tun hatten. Erklärt **worum es geht**, **was xl0g liefert**,
**was dieses Plugin tut** und **wie beides zusammenspielt**.

## Worum geht es?

Kunden suchen zunehmend **nicht mehr nur bei Google**, sondern fragen **KI-Assistenten**
(„ChatGPT, finde mir ein A5-Notizbuch, das bis Freitag da ist"). Damit ein Shop dort **empfohlen**
wird, müssen seine Produkte **maschinenlesbar** vorliegen — inklusive der Frage, die sonst kaum jemand
zuverlässig beantwortet: **„Bis wann ist es da?"**

**UCP** (Universal Commerce Protocol) ist ein junger, sich noch entwickelnder Standard für genau solche
**Produkt-plus-Lieferzeit-Feeds**, die KI-Einkaufsassistenten lesen können. Er ist noch nicht final —
wir liefern zum jeweils aktuellen Stand und pflegen nach.

## Die Rollenverteilung (der Kern)

> **xl0g liefert die Logistik-Wahrheit (den verlässlichen Liefertermin). Der Shop liefert die
> Produktdaten. Zusammen ergeben sie den maschinenlesbaren Feed.**

- **xl0g** kennt als Einziges den **präzisen, dynamischen Liefertermin** (Bearbeitungszeit + Transit +
  Auslastung + Bestellschluss). Das ist der **Differenzierer** — „2–4 Werktage" hat jeder, den *echten*
  Termin nur xl0g.
- Die **Produktdaten** (Name, Preis, Verfügbarkeit, Bild) liegen im **Shop** — dort sind sie die Quelle.

## Die drei Oberflächen (wo die Daten landen)

| # | Wer | Was |
|---|---|---|
| **1 · JSON-LD auf der Produktseite** | **dieses Plugin** | Fügt jedem Produkt maschinenlesbares `Product/Offer/shippingDetails` (inkl. xl0g-Termin) hinzu — das **lesen Google + KI-Crawler direkt auf der Shop-Seite**. |
| **2 · UCP-Feed** | **xl0g-Seite** | xl0g bündelt Shop-Produktfeed + Termin zu **einem** maschinenlesbaren Objekt — für KI-Agenten, die *einen* Feed lesen statt jede Seite zu crawlen. |
| **3 · Agent-Discovery** | **dieses Plugin** | `/llms.txt` + `/.well-known/…` zeigen KI-Agenten, **wo** die Signale liegen. |

## Was dieses Plugin konkret tut

1. Holt den **Termin (nur Zahlen)** von xl0g: `GET /api/promise?format=data`.
2. Zeigt ihn im Shop als **Widget** (Text/Sprache macht der Shop, pro Sales Channel).
3. Legt das **JSON-LD** (Oberfläche 1) + das **Discovery-Manifest** (Oberfläche 3) ab.

Der Termin wird **nie hier gerechnet** — xl0g liefert die Zahl, das Plugin präsentiert sie.

## Was ist heute fertig, was noch nicht?

- ✅ **Gebaut:** dieses Plugin (Widget + JSON-LD + Discovery) **und** die xl0g-Feed-Kette.
- ⏳ **Noch nicht live:** der xl0g-UCP-Feed ist **flag-off** (bewusste Freischaltung nötig: Flag an +
  Prüfung, dass die Shop-Artikelnummer als SKU passt). Und das Plugin muss im **echten Shop installiert +
  getestet** werden ([DEVELOPMENT.md](DEVELOPMENT.md), 4 Integrationspunkte).
- 🔜 **In Arbeit:** per-Produkt-Termin (heute ein repräsentativer Termin für alle Produkte; bald je nach
  Produktgröße/-gewicht).

## Weiterlesen

Installation → [INSTALLATION.md](INSTALLATION.md) · Einstellungen → [CONFIGURATION.md](CONFIGURATION.md) ·
Betrieb & Good Practice → [OPERATIONS.md](OPERATIONS.md) · Aufbau → [ARCHITECTURE.md](ARCHITECTURE.md) ·
Entwicklung → [DEVELOPMENT.md](DEVELOPMENT.md).
