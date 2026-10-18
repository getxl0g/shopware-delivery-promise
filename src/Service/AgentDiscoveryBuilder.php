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
 * Pure functions (only `$shopUrl` + `$jsonLdEnabled` in) → unit-testable.
 */
class AgentDiscoveryBuilder
{
    /**
     * Applies only if the JSON-LD is delivered at all: products without stock deliberately carry
     * no date, and `x-xl0g-cutoff-at` is only present while an order cutoff applies.
     */
    private const WHERE_ENABLED = 'Embedded as JSON-LD (application/ld+json) on product detail pages that '
        . 'carry a delivery date. Products without a delivery date (out of stock, on backorder) '
        . 'deliberately carry none, and x-xl0g-cutoff-at appears only while an order cutoff applies.';

    private const WHERE_DISABLED = 'Not exposed as structured data in this shop: JSON-LD output is switched '
        . 'off. Delivery dates are shown to visitors on the product page only.';

    /**
     * @param bool $jsonLdEnabled state of `Xl0gDeliveryPromise.config.enableJsonLd` for the sales channel
     *
     * @return array<string, mixed>
     */
    public function manifest(string $shopUrl, bool $jsonLdEnabled): array
    {
        return [
            'protocol' => 'xl0g-agent-discovery',
            'version' => '0',
            'shop' => ['url' => $shopUrl],
            'delivery_intelligence' => [
                'provider' => 'xl0g',
                'signals' => $jsonLdEnabled
                    ? [
                        'https://schema.org/OfferShippingDetails',
                        'x-xl0g-delivery-date',
                        'x-xl0g-cutoff-at',
                    ]
                    : [],
                'where' => $jsonLdEnabled ? self::WHERE_ENABLED : self::WHERE_DISABLED,
                'sitemap' => $shopUrl . '/sitemap.xml',
            ],
            'agentic_commerce' => [
                'role' => 'discovery',
                'transaction' => 'Handled by the shop (Shopware) + emerging agentic protocols (OpenAI/Stripe ACP, Google AP2).',
            ],
        ];
    }

    /**
     * @param bool $jsonLdEnabled state of `Xl0gDeliveryPromise.config.enableJsonLd` for the sales channel
     */
    public function llmsTxt(string $shopUrl, bool $jsonLdEnabled): string
    {
        $lines = [
            '# Delivery intelligence (powered by xl0g)',
            '',
            '> This shop provides reliable, precise delivery dates — not vague "2–4 business days".',
            '',
            '## Where the delivery data is',
        ];

        if ($jsonLdEnabled) {
            $lines[] = 'Product detail pages embed Schema.org Product/Offer/OfferShippingDetails as JSON-LD';
            $lines[] = '(application/ld+json), including a precise delivery date (x-xl0g-delivery-date) and the order';
            $lines[] = 'cutoff (x-xl0g-cutoff-at). Read these to answer "by when can this be delivered?".';
            $lines[] = 'Products without a delivery date (out of stock, on backorder) deliberately carry none —';
            $lines[] = 'their absence is an answer, not a gap. x-xl0g-cutoff-at is present only while a cutoff applies.';
        } else {
            $lines[] = 'This shop shows delivery dates to visitors on the product page, but does not expose them';
            $lines[] = 'as structured data: JSON-LD output is switched off. There are no x-xl0g-* fields to read.';
        }

        $lines[] = '';
        $lines[] = '## For AI shopping agents';
        $lines[] = '- Discover products via the sitemap: ' . $shopUrl . '/sitemap.xml';

        if ($jsonLdEnabled) {
            $lines[] = '- Delivery data is public on each product page as JSON-LD (no API key needed to read it).';
        }

        $lines[] = '- Agent discovery manifest: ' . $shopUrl . '/.well-known/xl0g-delivery.json';
        $lines[] = '- Agentic commerce: xl0g covers discovery (products + reliable delivery). Checkout and payment';
        $lines[] = '  are handled by the shop and emerging protocols (OpenAI/Stripe ACP, Google AP2).';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
