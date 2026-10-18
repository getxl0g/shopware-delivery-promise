# UCP & agentic commerce — overview for beginners

For people who have **never** dealt with “UCP”. Explains **what it is about**, **what xl0g delivers**,
**what this plugin does** and **how the two work together**.

## What is it about?

Customers increasingly **no longer search only on Google** but ask **AI assistants**
(“ChatGPT, find me an A5 notebook that arrives by Friday”). For a shop to be **recommended** there, its products
must be available in **machine-readable form** — including the question that hardly anyone otherwise answers
reliably: **“When will it arrive?”**

**UCP** (Universal Commerce Protocol) is a young, still-evolving standard for exactly such
**product-plus-delivery-time feeds** that AI shopping assistants can read. It is not final yet —
we deliver to the current state and keep it up to date.

## The division of roles (the core)

> **xl0g delivers the logistics truth (the reliable delivery date). The shop delivers the
> product data. Together they make up the machine-readable feed.**

- **xl0g** alone knows the **precise, dynamic delivery date** (processing time + transit +
  utilisation + order cutoff). That is the **differentiator** — everyone has “2–4 business days”, only xl0g has the *real*
  date.
- The **product data** (name, price, availability, image) lives in the **shop** — it is the source there.

## The three surfaces (where the data lands)

| # | Who | What |
|---|---|---|
| **1 · JSON-LD on the product page** | **this plugin** | Adds machine-readable `Product/Offer/shippingDetails` (including the xl0g date) to every product — **Google + AI crawlers read it directly on the shop page**. |
| **2 · UCP feed** | **xl0g side** | xl0g bundles the shop product feed + date into **one** machine-readable object — for AI agents that read *one* feed instead of crawling every page. |
| **3 · Agent discovery** | **this plugin** | `/llms.txt` + `/.well-known/…` show AI agents **where** the signals are. |

## What this plugin does, concretely

1. Fetches the **date (numbers only)** from xl0g: `GET /api/promise?format=data`.
2. Shows it in the shop as a **widget** (text/language is handled by the shop, per sales channel).
3. Stores the **JSON-LD** (surface 1) + the **discovery manifest** (surface 3).

The date is **never calculated here** — xl0g delivers the number, the plugin presents it.

## What is done today, what is not?

- ✅ **Built:** this plugin (widget + JSON-LD + discovery) **and** the xl0g feed chain.
- ⏳ **Not yet live:** the xl0g UCP feed is **flag-off** (deliberate enabling required: flag on +
  check that the shop product number fits as SKU). And the plugin has to be **installed + tested in the real
  shop** ([DEVELOPMENT.md](DEVELOPMENT.md), 4 integration points).
- 🔜 **In progress:** per-product date (today one representative date for all products; soon depending on
  product size/weight).

## Further reading

Installation → [INSTALLATION.md](INSTALLATION.md) · Settings → [CONFIGURATION.md](CONFIGURATION.md) ·
Operations & good practice → [OPERATIONS.md](OPERATIONS.md) · Architecture → [ARCHITECTURE.md](ARCHITECTURE.md) ·
Development → [DEVELOPMENT.md](DEVELOPMENT.md).
