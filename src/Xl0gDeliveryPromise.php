<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise;

use Shopware\Core\Framework\Plugin;

/**
 * xl0g Delivery Promise — Shopware 6 Plugin.
 *
 * Prinzip: xl0g liefert die ZAHLEN (Liefertermin, Cutoff, Transit-Tage) sprachneutral über
 * `GET /api/promise?format=data`. Text + Übersetzung macht DIESES Plugin — pro Shopware
 * Sales Channel, über das native Snippet-System. Zusätzlich emittiert es die
 * `shippingDetails`-Auszeichnung (JSON-LD) für Google-Rich-Results und KI-Assistenten.
 *
 * Open Source (MIT) + Freemium: der Plugin-Code ist offen; die kommerzielle Wirkung trägt
 * die xl0g-Lizenz (die Promise-Engine hinter der API), nicht der Plugin-Code.
 *
 * Aufbau (Phasen): B1 Scaffold · B2 Storefront-Widget (Twig + JS-Countdown) · B3 JSON-LD-Emitter ·
 * B4 Admin-Config + Checkout · B5 Agent-Discovery-Manifest · B6 Tests + Doku.
 */
class Xl0gDeliveryPromise extends Plugin
{
}
