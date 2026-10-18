# Operations & good practice — xl0g Delivery Promise

Recommendations for the right **setup**, **use** and **monitoring** in production.

## 1 · Setup (good practice)

### API key: minimally privileged
- A **dedicated** xl0g key just for the plugin, permission **exclusively `promise:read`** (least privilege).
  Do not reuse admin/full-access keys.
- **One** key is enough for all sales channels; it stays **server-side** (never in the browser).
- Suspect a leak? **Rotate** the key in the xl0g dashboard, enter the new one in the plugin — nothing more is needed.

### Configure each sales channel deliberately
At the top of the config dialog **choose the channel**, then per channel:
- Set **API base URL + key**.
- **Shipping method**: the shop’s representative standard shipping method (v0) — it determines the displayed date.
- **Destination country**: the channel’s main market (for the JSON-LD `shippingDestination`).
- **JSON-LD on** (recommended): Google rich results + AI assistants. Only switch it **off** if another plugin
  already provides identical `shippingDetails` markup (avoid duplicate markup).
- **Checkout widget on** if the date promise near the “Buy” button should support conversion.

### Texts
- Adapt the snippets (`xl0g.deliveryPromise.*`) per sales channel/language to the brand in the shop’s snippet editor.
  **Do not remove the placeholders `{date}` / `{countdown}`** (otherwise the number is missing).

## 2 · Use (good practice)
- **Test one shop completely first**, then roll out the rest with the same configuration.
- After every config change **clear the storefront cache** (admin → Caches, or `bin/console cache:clear`).
- **Verify:**
  - PDP: the delivery promise appears below the buy box.
  - JSON-LD: the source contains `application/ld+json` with `OfferShippingDetails` → **Google’s Rich Results Test**.
  - Load `https://<shop>/llms.txt` + `/.well-known/xl0g-delivery.json`.
- **Fail-safe by design:** wrong key, xl0g unreachable or no date → the widget **hides itself** (no broken widget,
  no blocked page build). An empty widget means check config/reachability, **not** a shop error.

## 3 · Monitoring (good practice)
- **Visual check**: is the widget there on a real PDP? Is the JSON-LD valid (Rich Results Test)?
- **xl0g API health**: the plugin depends on the xl0g promise API. Slow (>3 s) or down → the widget hides
  **silently** (timeout 3 s, error swallowed). That is why **the xl0g side should be monitored too**.
- **Google Search Console**: watch `shippingDetails`/shipping rich results + structure errors.
- **Shopware log**: since the plugin deliberately swallows errors (fail-safe), to debug check the response of
  `GET /xl0g/delivery-promise` — **200 + JSON** = date delivered, **204** = no date/config.
- **Metric** “widget hit rate”: roughly the ratio of 200 vs. 204 on `/xl0g/delivery-promise`.
- **After deploys**: if a storefront build/deploy fails *once*, the cause is, in experience,
  **transient** (build/cache flake) — a second attempt usually suffices; only then look deeper.

## 4 · Security
- The API key stays **server-side** (never in JS/HTML) — the plugin enforces this.
- Only the **public** delivery numbers + the JSON-LD are delivered.
- `/llms.txt` + `/.well-known/` only give **hints** (no auth, no customer data).

---
Config details: [CONFIGURATION.md](CONFIGURATION.md) · Architecture: [ARCHITECTURE.md](ARCHITECTURE.md) ·
Development/tests: [DEVELOPMENT.md](DEVELOPMENT.md) · Rollout: [INSTALLATION.md](INSTALLATION.md).

## 5 · Updates and delivery

**Source:** this repository. Every version is a tag (`v0.7.0` …) with a release and the ZIP
`Xl0gDeliveryPromise-<version>.zip` (built with `git archive --prefix=Xl0gDeliveryPromise/`).

**Recommended: Composer.** The shop pulls in `"xl0g/shopware-delivery-promise": "^0.7"` from this repository (see
[INSTALLATION.md](INSTALLATION.md)). Every compatible version arrives with the shop’s next deploy — through the same
route the shop is delivered by anyway, with `plugin:update`, storefront build and cache clear. Anyone who wants more
automation has Renovate or Dependabot open a merge request per release.

**Deliberately no self-update in the shop:**
- **It does not work everywhere:** shops on Shopware PaaS or platform.sh have a read-only file system at runtime;
  code only changes there through the build.
- **Supply-chain risk:** code that reloads itself from the network and runs it turns a compromised account into
  access to every shop. Shops’ security reviews rightly reject that.
- **An update is more than files:** migrations, `plugin:update`, storefront build, cache — that belongs in the
  shop’s pipeline, with its tests.
