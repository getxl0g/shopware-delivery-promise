# Betrieb & Good Practice — xl0g Lieferversprechen

Empfehlungen für die richtige **Einstellung**, **Nutzung** und **Überwachung** im Produktivbetrieb.

## 1 · Einstellung (Good Practice)

### API-Key: minimal berechtigt
- Ein **eigener** xl0g-Key nur fürs Plugin, Permission **ausschließlich `promise:read`** (Least Privilege).
  Keine Admin-/Full-Access-Keys wiederverwenden.
- **Ein** Key genügt für alle Sales Channels; er bleibt **server-seitig** (nie im Browser).
- Verdacht auf Leak? Key im xl0g-Dashboard **rotieren**, im Plugin neu eintragen — mehr ist nicht nötig.

### Pro Sales Channel bewusst konfigurieren
Oben im Config-Dialog den **Kanal wählen**, dann je Kanal:
- **API-Basis-URL + Key** setzen.
- **Versandart**: die repräsentative Standard-Versandart des Shops (v0) — sie bestimmt den angezeigten Termin.
- **Ziel-Land**: der Hauptmarkt des Kanals (fürs JSON-LD `shippingDestination`).
- **JSON-LD an** (empfohlen): Google-Rich-Results + KI-Assistenten. Nur **aus**, wenn ein anderes Plugin
  bereits identisches `shippingDetails`-Markup liefert (Doppel-Markup vermeiden).
- **Checkout-Widget an**, wenn die Termin-Zusage nah am „Kaufen"-Button die Conversion stützen soll.

### Texte
- Snippets (`xl0g.deliveryPromise.*`) pro Sales Channel/Sprache im Shop-Snippet-Editor an die Marke
  anpassen. **Platzhalter `{date}` / `{countdown}` nicht entfernen** (sonst fehlt die Zahl).

## 2 · Nutzung (Good Practice)
- **Erst 1 Shop vollständig testen**, dann die restlichen mit gleicher Konfiguration ausrollen.
- Nach jeder Config-Änderung **Storefront-Cache leeren** (Admin → Caches, bzw. `bin/console cache:clear`).
- **Verifizieren:**
  - PDP: das Lieferversprechen erscheint unter dem Kauf-Bereich.
  - JSON-LD: Quelltext enthält `application/ld+json` mit `OfferShippingDetails` → **Googles Rich-Results-Test**.
  - `https://<shop>/llms.txt` + `/.well-known/xl0g-delivery.json` laden.
- **Fail-safe by design:** falscher Key, xl0g nicht erreichbar oder kein Termin → das Widget **blendet
  sich aus** (kein kaputtes Widget, kein blockierter Seitenaufbau). Leeres Widget = Config/Erreichbarkeit
  prüfen, **kein** Shop-Fehler.

## 3 · Monitoring (Good Practice)
- **Sichtprüfung**: Widget auf einer echten PDP da? JSON-LD valide (Rich-Results-Test)?
- **xl0g-API-Gesundheit**: das Plugin hängt an der xl0g-Promise-API. Langsam (>3 s) oder down → Widget
  blendet sich **still** aus (Timeout 3 s, Fehler geschluckt). Darum die **xl0g-Seite mit überwachen**.
- **Google Search Console**: `shippingDetails`/Versand-Rich-Results + Struktur-Fehler beobachten.
- **Shopware-Log**: da das Plugin Fehler bewusst schluckt (fail-safe), zum Debuggen die Antwort von
  `GET /xl0g/delivery-promise` prüfen — **200 + JSON** = Termin ausgeliefert, **204** = kein Termin/Config.
- **Kennzahl** „Widget-Trefferquote": grob das Verhältnis 200 vs. 204 auf `/xl0g/delivery-promise`.
- **Nach Deploys**: schlägt ein Storefront-Build/Deploy *einmal* fehl, ist die Ursache erfahrungsgemäß
  **transient** (Build-/Cache-Flake) — ein zweiter Anlauf reicht meist; erst dann tiefer schauen.

## 4 · Sicherheit
- API-Key bleibt **server-seitig** (nie im JS/HTML) — das Plugin erzwingt das.
- Ausgeliefert werden nur die **öffentlichen** Liefer-Zahlen + das JSON-LD.
- `/llms.txt` + `/.well-known/` geben nur **Hinweise** (keine Auth, keine Kundendaten).

---
Konfig-Details: [CONFIGURATION.md](CONFIGURATION.md) · Aufbau: [ARCHITECTURE.md](ARCHITECTURE.md) ·
Entwicklung/Tests: [DEVELOPMENT.md](DEVELOPMENT.md) · Rollout: [INSTALLATION.md](INSTALLATION.md).

## 5 · Updates und Auslieferung

**Bezugsquelle:** dieses Repository. Jede Version ist ein Tag (`v0.7.0` …) mit einem Release und dem ZIP
`Xl0gDeliveryPromise-<Version>.zip` (gebaut mit `git archive --prefix=Xl0gDeliveryPromise/`).

**Empfohlen: Composer.** Der Shop bindet `"xl0g/shopware-delivery-promise": "^0.7"` aus diesem Repository ein (siehe
[INSTALLATION.md](INSTALLATION.md)). Jede kompatible Version kommt mit dem nächsten Deploy des Shops — über denselben
Weg, auf dem der Shop ohnehin ausgeliefert wird, mit `plugin:update`, Storefront-Build und Cache-Leeren. Wer mehr
Automatik will, lässt Renovate oder Dependabot je Release einen Merge-Request öffnen.

**Bewusst kein Selbst-Update im Shop:**
- **Es geht nicht überall:** Shops auf Shopware PaaS oder platform.sh haben zur Laufzeit ein schreibgeschütztes
  Dateisystem; Code ändert sich dort nur über den Build.
- **Lieferketten-Risiko:** Code, der sich selbst aus dem Netz nachlädt und ausführt, macht aus einem kompromittierten
  Konto einen Zugang zu jedem Shop. Das lehnen Sicherheitsprüfungen bei Shops zu Recht ab.
- **Ein Update ist mehr als Dateien:** Migrationen, `plugin:update`, Storefront-Build, Cache — das gehört in die
  Pipeline des Shops, mit deren Test.
