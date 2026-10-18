# Konfiguration — xl0g Lieferversprechen

Alle Einstellungen unter **Einstellungen → Erweiterungen → xl0g Lieferversprechen** — oben lässt sich
der **Sales Channel** wählen, für den die Werte gelten (jeder Kanal/Sprache eigene Werte).

## Karte „Verbindung"

| Feld | Pflicht | Default | Bedeutung |
|---|---|---|---|
| **xl0g API-Basis-URL** | ja | — | Basis-URL deines xl0g-Tenants, ohne abschließenden Slash, z. B. `https://xl0g.com/<tenant>/api`. |
| **xl0g API-Schlüssel** | ja | — | API-Key mit Berechtigung `promise:read`. Wird **server-seitig** gehalten, nie im Browser. |

Fehlt eines der beiden Felder, zeigt das Plugin **nichts** — kein kaputtes Widget, kein JSON-LD.

## Karte „Anzeige & Verhalten"

| Feld | Default | Bedeutung |
|---|---|---|
| **xl0g-Versandart** | `standard` | Welche Versandart bei xl0g abgefragt wird. v0: eine repräsentative Art (per-SKU folgt als Track-A-Ausbau). |
| **Ziel-Land (ISO)** | `DE` | Ländercode für die `shippingDestination` im JSON-LD. |
| **JSON-LD auf der Produktseite** | an | Schema.org `shippingDetails` (Google-Rich-Results + KI-Agenten). Steuert **auch** `llms.txt` und `/.well-known/xl0g-delivery.json`: steht der Schalter aus, kündigen beide keine `x-xl0g-*`-Felder mehr an — siehe unten. |
| **Widget im Checkout** | aus | Zeigt das Lieferversprechen zusätzlich auf der Bestellabschluss-Seite. |
| **Seitenaufrufe an xl0g melden** (ab 0.7.0) | aus | Meldet je Produktseite Artikelnummer, Shop und Art des Besuchers (Mensch, KI-Agent, Suchdienst, Werkzeug) an xl0g; sichtbar unter „Produkt-Check für KI → Wer kommt?“. Kein User-Agent, keine Adresse — siehe unten. |

### Der JSON-LD-Schalter wirkt auf zwei Ebenen

Der Schalter ist **pro Sales Channel** gesetzt (`Xl0gDeliveryPromise.config.enableJsonLd`). Er entscheidet
nicht nur, ob die Produktseite JSON-LD trägt, sondern auch, was `llms.txt` und das Discovery-Manifest
Agenten **ankündigen**:

| Schalter | Produktseite | `llms.txt` + Manifest |
|---|---|---|
| **an** | `Product`/`Offer`/`OfferShippingDetails` mit `x-xl0g-delivery-date`, dazu `x-xl0g-cutoff-at`, solange ein Annahmeschluss greift | nennen beide Felder und sagen dazu, dass Artikel ohne Termin bewusst keinen tragen |
| **aus** | kein JSON-LD vom Plugin | nennen **keine** Feldnamen, sondern sagen, dass dieser Shop die Termine nicht als strukturierte Daten führt |

Beides gehört zusammen: ein Manifest, das Felder verspricht, die der Shop nicht ausliefert, schickt
Agenten auf eine Suche, die leer ausgeht — und die fallen dann auf die Spanne zurück, die xl0g ablöst.

> **Prüfen, ob der Schalter in einem Shop steht:** `curl -s https://<shop>/.well-known/xl0g-delivery.json`
> — stehen unter `delivery_intelligence.signals` die beiden `x-xl0g-*`-Felder, ist er an; ist die Liste
> leer, ist er aus. Dann auf einer Produktseite gegenprüfen: `curl -s <PDP-URL> | grep -c x-xl0g`.

### Seitenaufrufe an xl0g melden (ab 0.7.0)

Mit dem Schalter sieht der Shop in xl0g, **welche Produktseiten** aufgerufen werden und **von wem** — Menschen,
KI-Agenten (namentlich: GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot …), Suchdienste oder Werkzeuge.

| Was geht an xl0g | Was nicht |
|---|---|
| Artikelnummer, Host des Shops, Art des Besuchers, bei KI-Agenten der Name aus der festen Liste | User-Agent, IP-Adresse, Cookies, Sitzung, Warenkorb |

- **Wo gezählt wird:** Browser über das Widget (der Abruf läuft ohnehin), alle anderen beim Erzeugen der Produktseite.
  Jeder Besuch zählt einmal.
- **Untergrenze bei Agenten:** Seiten, die der Shop aus seinem HTTP-Cache ausliefert, erreichen das Plugin nicht.
- **Datenschutz:** keine Personendaten. Ob der Hinweis in der Datenschutzerklärung des Shops ergänzt wird, entscheidet
  der Shop-Betreiber.

## Texte anpassen (pro Sales Channel)

Die angezeigten Texte sind **Shopware-Snippets** — im Admin unter **Einstellungen → Snippets** pro
Sales Channel überschreibbar, ganz ohne Code:

| Snippet-Key | Default (DE) | Platzhalter |
|---|---|---|
| `xl0g.deliveryPromise.line` | „Geliefert bis {date} — bestellen Sie in den nächsten {countdown}." | `{date}`, `{countdown}` |
| `xl0g.deliveryPromise.unitHours` | „Std." | — |
| `xl0g.deliveryPromise.unitMinutes` | „Min." | — |

`{date}` wird lokalisiert gerendert (Wochentag, Tag und Monat in der Sprache des Verkaufskanals), `{countdown}` tickt live gegen den
xl0g-Cutoff. Prinzip: **die Zahlen kommen von xl0g, der Text lebt hier** — so passt die Sprache nativ
zu deinem mehrsprachigen Shop.

## Woher kommt der API-Key?

Aus deinem xl0g-Tenant (Dashboard → API-Zugänge). Du brauchst noch einen Zugang?
→ [xl0g.com](https://xl0g.com)
