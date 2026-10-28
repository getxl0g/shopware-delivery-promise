<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Builds the agent discovery manifest (JSON) + the `llms.txt` — the **discovery half** of
 * agentic commerce. Shows AI shopping agents where the (public) delivery signals are.
 *
 * Both methods need `$jsonLdEnabled` — the actual state of the switch
 * `Xl0gDeliveryPromise.config.enableJsonLd` for the serving sales channel. The parameter is
 * deliberately **without a default**: the manifest may only announce what the shop also delivers. If
 * the switch is off, {@see StructuredDataSubscriber} adds no JSON-LD — then no field may be
 * promised here either, otherwise the manifest sends agents on a search that comes up empty.
 *
 * `$coreJsonLd` says whether the plugin merges its data into Shopware's own product JSON-LD (flag `JSON_LD_DATA`).
 * There the plugin writes no `x-xl0g-*` fields (they are not Schema.org vocabulary); the date is expressed as
 * handlingTime + transitTime with minValue = maxValue. The texts then name that instead of the extra fields.
 *
 * `$widgetOnPage` says whether the visible delivery promise is switched on for the product page
 * (`deliveryPromiseProductPage`). Its server-rendered element is the one place of the date that does not depend on
 * which JSON-LD markup the page ends up with (own block, SEO plugin markup, core JSON-LD): the plugin cannot know at
 * the time `llms.txt` is requested whether another plugin's markup leaves the `x-xl0g-*` fields out, so these fields
 * are only ever described as "where present" and the element is named as the reliable source.
 *
 * `$feedUrl` is the address of the public product feed (optional, default null = not listed). It is only ever
 * listed when it is known: {@see self::feedUrlFromApiBase()} derives it from the configured xl0g API base URL and
 * returns null if that is empty or not a plain http(s) address. The feed is described neutrally — no protocol
 * conformance is claimed.
 *
 * Pure functions (only `$shopUrl`, `$jsonLdEnabled`, `$coreJsonLd` and `$feedUrl` in) → unit-testable.
 */
class AgentDiscoveryBuilder
{
    /**
     * Applies only if the JSON-LD is delivered at all: products without stock deliberately carry
     * no date, and `x-xl0g-cutoff-at` is only present while an order cutoff applies.
     */
    private const WHERE_ENABLED = 'May be embedded as JSON-LD (application/ld+json) on product detail pages that '
        . 'carry a delivery date: OfferShippingDetails, plus the optional fields x-xl0g-delivery-date and '
        . 'x-xl0g-cutoff-at. The x-xl0g-* fields are not guaranteed on every page (markup of another plugin can '
        . 'leave them out), so treat them as optional. Products without a delivery date (out of stock, on backorder) '
        . 'deliberately carry none, and x-xl0g-cutoff-at appears only while an order cutoff applies.';

    private const WHERE_CORE = 'Embedded in the product JSON-LD (application/ld+json) of product detail pages as '
        . 'OfferShippingDetails: handlingTime and transitTime in business days, each with minValue = maxValue, so the '
        . 'delivery date is the day of the page view plus both values. Products without a delivery date (out of stock, '
        . 'on backorder) carry no values from xl0g. There are no x-xl0g-* fields in this shop.';

    private const WHERE_DISABLED = 'Not exposed as structured data in this shop: JSON-LD output is switched '
        . 'off. Delivery dates are shown to visitors on the product page only.';

    private const ELEMENT_NOTE = 'The visible delivery promise on the product page is an HTML element with the attribute '
        . 'data-xl0g-delivery-promise: data-promised-date is the delivery date (YYYY-MM-DD), data-cutoff-at the order '
        . 'cutoff (ISO 8601, present only while a cutoff applies). It is rendered with the page, independent of the JSON-LD.';

    private const FEED_DESCRIPTION = 'Product feed with delivery dates (JSON, xl0g schema)';

    /**
     * The public product feed lives at `{apiBaseUrl}/ucp-feed` of the xl0g tenant (no authentication).
     * Returns null — so nothing is listed — if the base URL is empty, not http(s), or carries a query/fragment/whitespace.
     */
    public static function feedUrlFromApiBase(string $apiBaseUrl): ?string
    {
        $base = rtrim(trim($apiBaseUrl), '/');

        if (1 !== preg_match('#^https?://[^\s?\#]+$#i', $base)) {
            return null;
        }

        return $base . '/ucp-feed';
    }

    /**
     * @param bool        $jsonLdEnabled state of `Xl0gDeliveryPromise.config.enableJsonLd` for the sales channel
     * @param string|null $feedUrl       public product feed address; null = not known, not listed
     * @param bool        $widgetOnPage  state of `deliveryPromiseProductPage` for the sales channel
     *
     * @return array<string, mixed>
     */
    public function manifest(string $shopUrl, bool $jsonLdEnabled, bool $coreJsonLd, ?string $feedUrl = null, bool $widgetOnPage = false): array
    {
        $manifest = [
            'protocol' => 'xl0g-agent-discovery',
            'version' => '0',
            'shop' => ['url' => $shopUrl],
            'delivery_intelligence' => [
                'provider' => 'xl0g',
                'signals' => match (true) {
                    !$jsonLdEnabled => [],
                    default => ['https://schema.org/OfferShippingDetails'],
                },
                'where' => match (true) {
                    !$jsonLdEnabled => self::WHERE_DISABLED,
                    $coreJsonLd => self::WHERE_CORE,
                    default => self::WHERE_ENABLED,
                },
                'sitemap' => $shopUrl . '/sitemap.xml',
            ],
            'agentic_commerce' => [
                'role' => 'discovery',
                'transaction' => 'Handled by the shop (Shopware) + emerging agentic protocols (OpenAI/Stripe ACP, Google AP2).',
            ],
        ];

        if ($jsonLdEnabled && !$coreJsonLd) {
            // Optional on purpose: present where the plugin's own or an injected block is rendered, never guaranteed.
            $manifest['delivery_intelligence']['optional_signals'] = ['x-xl0g-delivery-date', 'x-xl0g-cutoff-at'];
        }

        if ($widgetOnPage) {
            $manifest['delivery_intelligence']['page_element'] = [
                'selector' => '[data-xl0g-delivery-promise]',
                'delivery_date_attribute' => 'data-promised-date',
                'cutoff_attribute' => 'data-cutoff-at',
                'description' => self::ELEMENT_NOTE,
            ];
        }

        if (null !== $feedUrl && '' !== $feedUrl) {
            $manifest['product_feed'] = [
                'url' => $feedUrl,
                'format' => 'application/json',
                'description' => self::FEED_DESCRIPTION,
            ];
        }

        return $manifest;
    }

    /**
     * @param bool        $jsonLdEnabled state of `Xl0gDeliveryPromise.config.enableJsonLd` for the sales channel
     * @param string|null $feedUrl       public product feed address; null = not known, not listed
     * @param bool        $widgetOnPage  state of `deliveryPromiseProductPage` for the sales channel
     */
    public function llmsTxt(string $shopUrl, bool $jsonLdEnabled, bool $coreJsonLd, ?string $feedUrl = null, bool $widgetOnPage = false): string
    {
        $lines = [
            '# Delivery intelligence (powered by xl0g)',
            '',
            '> This shop provides reliable, precise delivery dates — not vague "2–4 business days".',
            '',
            '## Where the delivery data is',
        ];

        if ($jsonLdEnabled && $coreJsonLd) {
            $lines[] = 'Product detail pages embed Schema.org Product/Offer/OfferShippingDetails as JSON-LD';
            $lines[] = '(application/ld+json). handlingTime and transitTime are given in business days, each with';
            $lines[] = 'minValue = maxValue: the delivery date is the day of the page view plus both values.';
            $lines[] = 'Products without a delivery date (out of stock, on backorder) carry no values from xl0g.';
            $lines[] = 'There are no x-xl0g-* fields in this shop.';
        } elseif ($jsonLdEnabled) {
            $lines[] = 'Product detail pages may embed Schema.org Product/Offer/OfferShippingDetails as JSON-LD';
            $lines[] = '(application/ld+json) with handlingTime and transitTime and, as optional extras, a precise';
            $lines[] = 'delivery date (x-xl0g-delivery-date) and the order cutoff (x-xl0g-cutoff-at). The x-xl0g-* fields are';
            $lines[] = 'NOT guaranteed on every page: markup of another plugin can leave them out. Do not rely on them.';
            $lines[] = 'Products without a delivery date (out of stock, on backorder) deliberately carry none —';
            $lines[] = 'their absence is an answer, not a gap. x-xl0g-cutoff-at is present only while a cutoff applies.';
        } else {
            $lines[] = 'This shop shows delivery dates to visitors on the product page, but does not expose them';
            $lines[] = 'as structured data: JSON-LD output is switched off. There are no x-xl0g-* fields to read.';
        }

        if ($widgetOnPage) {
            $lines[] = 'Reliable source of the date: ' . self::ELEMENT_NOTE;
        }

        $lines[] = '';
        $lines[] = '## For AI shopping agents';
        $lines[] = '- Discover products via the sitemap: ' . $shopUrl . '/sitemap.xml';

        if ($jsonLdEnabled) {
            $lines[] = '- Delivery data is public on the product pages as JSON-LD (no API key needed to read it).';
        }

        if (null !== $feedUrl && '' !== $feedUrl) {
            $lines[] = '- ' . self::FEED_DESCRIPTION . ': ' . $feedUrl;
        }

        $lines[] = '- Agent discovery manifest: ' . $shopUrl . '/.well-known/xl0g-delivery.json';
        $lines[] = '- Agentic commerce: xl0g covers discovery (products + reliable delivery). Checkout and payment';
        $lines[] = '  are handled by the shop and emerging protocols (OpenAI/Stripe ACP, Google AP2).';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
