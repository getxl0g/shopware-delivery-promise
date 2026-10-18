<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Baut das Agent-Discovery-Manifest (JSON) + die `llms.txt` — die **Discovery-Hälfte** des
 * agentic commerce. Zeigt KI-Einkaufsagenten, wo die (öffentlichen) Liefer-Signale liegen.
 *
 * Beide Methoden brauchen `$jsonLdEnabled` — den tatsächlichen Stand des Schalters
 * `Xl0gDeliveryPromise.config.enableJsonLd` für den ausliefernden Sales Channel. Der Parameter ist
 * bewusst **ohne Default**: das Manifest darf nur ankündigen, was der Shop auch ausliefert. Steht
 * der Schalter aus, fügt {@see StructuredDataSubscriber} kein JSON-LD an — dann darf hier auch kein
 * Feld versprochen werden, sonst schickt das Manifest Agenten auf eine Suche, die leer ausgeht.
 *
 * Reine Funktionen (nur `$shopUrl` + `$jsonLdEnabled` rein) → unit-testbar.
 */
class AgentDiscoveryBuilder
{
    /**
     * Gilt nur, wenn das JSON-LD überhaupt ausgeliefert wird: Produkte ohne Bestand tragen bewusst
     * keinen Termin, und `x-xl0g-cutoff-at` steht nur an, solange ein Annahmeschluss greift.
     */
    private const WO_AKTIV = 'Embedded as JSON-LD (application/ld+json) on product detail pages that '
        . 'carry a delivery date. Products without a delivery date (out of stock, on backorder) '
        . 'deliberately carry none, and x-xl0g-cutoff-at appears only while an order cutoff applies.';

    private const WO_AUS = 'Not exposed as structured data in this shop: JSON-LD output is switched '
        . 'off. Delivery dates are shown to visitors on the product page only.';

    /**
     * @param bool $jsonLdEnabled Stand von `Xl0gDeliveryPromise.config.enableJsonLd` für den Sales Channel
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
                'where' => $jsonLdEnabled ? self::WO_AKTIV : self::WO_AUS,
                'sitemap' => $shopUrl . '/sitemap.xml',
            ],
            'agentic_commerce' => [
                'role' => 'discovery',
                'transaction' => 'Handled by the shop (Shopware) + emerging agentic protocols (OpenAI/Stripe ACP, Google AP2).',
            ],
        ];
    }

    /**
     * @param bool $jsonLdEnabled Stand von `Xl0gDeliveryPromise.config.enableJsonLd` für den Sales Channel
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
