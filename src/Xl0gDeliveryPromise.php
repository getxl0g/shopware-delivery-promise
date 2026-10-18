<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise;

use Shopware\Core\Framework\Plugin;

/**
 * xl0g Delivery Promise — Shopware 6 Plugin.
 *
 * Principle: xl0g delivers the NUMBERS (delivery date, cutoff, transit days) in a language-neutral form via
 * `GET /api/promise?format=data`. Text + translation is done by THIS plugin — per Shopware
 * sales channel, through the native snippet system. In addition it emits the
 * `shippingDetails` markup (JSON-LD) for Google rich results and AI assistants.
 *
 * Open source (MIT) + freemium: the plugin code is open; the commercial effect rests on
 * the xl0g licence (the promise engine behind the API), not on the plugin code.
 *
 * Structure (phases): B1 scaffold · B2 storefront widget (Twig + JS countdown) · B3 JSON-LD emitter ·
 * B4 admin config + checkout · B5 agent discovery manifest · B6 tests + docs.
 */
class Xl0gDeliveryPromise extends Plugin
{
}
