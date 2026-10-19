<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Builds or extends the Schema.org `Product`/`Offer`/`OfferShippingDetails` JSON-LD.
 * xl0g's contribution: precise `shippingDetails` + the exact date (`x-xl0g-delivery-date`).
 * Pure functions (no dependencies) → unit-testable.
 *
 * Three modes (never two contradicting Product blocks per page):
 *  1. {@see injectIntoLdBlocks()}: if product markup of an SEO plugin is already present
 *     (e.g. DreiSC SEO Pro), shippingDetails + date are injected THERE —
 *     `availability` stays untouched, the SEO plugin is its only source.
 *  2. {@see injectIntoProductData()}: if the Shopware core outputs JSON-LD itself (feature flag
 *     JSON_LD_DATA, standard from 6.8), the shippingDetails are merged into its Product/ProductGroup
 *     node (Twig function `xl0g_inject_shipping_details`) — without the `x-xl0g-*` extra fields,
 *     so that the core block stays validator-clean.
 *  3. {@see build()}: without foreign markup and without core JSON-LD the plugin builds its own
 *     block as before; the availability then comes from {@see availability()}.
 */
class StructuredDataBuilder
{
    /**
     * @param \Closure(): \DateTimeImmutable|null $now clock for the handling time (tests); null = the real time
     */
    public function __construct(private readonly ?\Closure $now = null)
    {
    }

    /**
     * Availability following the same scheme as common SEO plugins (DreiSC defaults):
     * closeout (`isCloseout`) with stock ⇒ LimitedAvailability, without stock ⇒ SoldOut
     * (cannot be reordered); otherwise simply stock ⇒ InStock/OutOfStock.
     */
    public function availability(bool $isCloseout, int $stock): string
    {
        if ($isCloseout) {
            return $stock > 0 ? 'https://schema.org/LimitedAvailability' : 'https://schema.org/SoldOut';
        }

        return $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
    }

    /**
     * Injects the xl0g data into existing JSON-LD blocks (e.g. the
     * `dreiscSeoInstallmentRichSnippetData` extension): per `Product`/`ProductGroup` block
     * shippingDetails/transitTime into all offers + `x-xl0g-*` fields on the product node.
     * Existing values (availability, shippingRate, shippingDestination) are kept — transitTime and, with a
     * ship date from xl0g, handlingTime are replaced by the precise xl0g values.
     *
     * @param array<int|string, mixed> $ldBlocks
     * @return array<int|string, mixed>|null the modified blocks — or null if no Product block was
     *                                       found (the caller then renders its own fallback block).
     */
    public function injectIntoLdBlocks(array $ldBlocks, PromiseResult $promise, string $shippingCountry = 'DE'): ?array
    {
        $injected = false;
        foreach ($ldBlocks as $key => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $type = $block['@type'] ?? null;
            if ($type !== 'Product' && $type !== 'ProductGroup') {
                continue;
            }
            $ldBlocks[$key] = $this->injectIntoProductNode($block, $promise, $shippingCountry);
            $injected = true;
        }

        return $injected ? $ldBlocks : null;
    }

    /**
     * Extends the Product/ProductGroup node of the Shopware core JSON-LD
     * (json-ld-product.html.twig, variable `productData`) with the xl0g shippingDetails.
     * Existing core values (availability, seller) are kept; transitTime and — with a ship date from xl0g —
     * handlingTime are set precisely (a handling time from the Shopware delivery time is replaced), a missing
     * shippingDestination is added. By default WITHOUT the
     * `x-xl0g-*` extra fields, because they are not Schema.org vocabulary and the core block should
     * stay validator-clean. Anything other than Product/ProductGroup is returned unchanged.
     *
     * @param array<string, mixed> $productData
     * @return array<string, mixed>
     */
    public function injectIntoProductData(
        array $productData,
        PromiseResult $promise,
        string $shippingCountry = 'DE',
        bool $withXl0gMeta = false
    ): array {
        $type = $productData['@type'] ?? null;
        if ($type !== 'Product' && $type !== 'ProductGroup') {
            return $productData;
        }

        return $this->injectIntoProductNode($productData, $promise, $shippingCountry, $withXl0gMeta);
    }

    /**
     * Own, complete Product block — only the fallback when there is no SEO plugin markup on the page.
     * $availability comes from the caller (via {@see availability()}), so that the derivation lives in
     * exactly one place and stays testable.
     *
     * @return array<string, mixed>
     */
    public function build(
        ?string $name,
        ?string $sku,
        ?string $price,
        ?string $currency,
        string $availability,
        PromiseResult $promise,
        string $shippingCountry = 'DE'
    ): array {
        $offer = [
            '@type' => 'Offer',
            'availability' => $availability,
            'shippingDetails' => $this->shippingDetails($promise, $shippingCountry),
        ];
        if ($price !== null && $price !== '') {
            $offer['price'] = $price;
        }
        if ($currency !== null && $currency !== '') {
            $offer['priceCurrency'] = $currency;
        }

        $product = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'offers' => $offer,
            'x-xl0g-delivery-date' => $promise->promisedDate,
        ];
        if ($name !== null && $name !== '') {
            $product['name'] = $name;
        }
        if ($sku !== null && $sku !== '') {
            $product['sku'] = $sku;
        }
        if ($promise->cutoffAt !== null) {
            $product['x-xl0g-cutoff-at'] = $promise->cutoffAt;
        }

        return $product;
    }

    /**
     * One Product/ProductGroup node: `x-xl0g-*` on the product, xl0g shipping data into the offers;
     * for a ProductGroup additionally recursively into all `hasVariant` child products.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function injectIntoProductNode(array $node, PromiseResult $promise, string $shippingCountry, bool $withXl0gMeta = true): array
    {
        if ($withXl0gMeta) {
            $node['x-xl0g-delivery-date'] = $promise->promisedDate;
            if ($promise->cutoffAt !== null) {
                $node['x-xl0g-cutoff-at'] = $promise->cutoffAt;
            }
        }

        if (isset($node['offers'])) {
            $node['offers'] = $this->injectIntoOffers($node['offers'], $promise, $shippingCountry);
        }

        if (isset($node['hasVariant']) && \is_array($node['hasVariant'])) {
            foreach ($node['hasVariant'] as $key => $variant) {
                if (\is_array($variant) && ($variant['@type'] ?? null) === 'Product') {
                    $node['hasVariant'][$key] = $this->injectIntoProductNode($variant, $promise, $shippingCountry, $withXl0gMeta);
                }
            }
        }

        return $node;
    }

    /**
     * `offers` can be a single Offer object (has `@type`) or a list.
     */
    private function injectIntoOffers(mixed $offers, PromiseResult $promise, string $shippingCountry): mixed
    {
        if (!\is_array($offers)) {
            return $offers;
        }

        if (isset($offers['@type'])) {
            return $this->injectIntoOffer($offers, $promise, $shippingCountry);
        }

        foreach ($offers as $key => $offer) {
            if (\is_array($offer)) {
                $offers[$key] = $this->injectIntoOffer($offer, $promise, $shippingCountry);
            }
        }

        return $offers;
    }

    /**
     * Deliberately NO writing of `availability` — there is exactly one source for it per page (the
     * existing SEO or core markup). Existing shippingDetails are extended rather than replaced:
     * transitTime and — with a ship date from xl0g — handlingTime are set precisely, a missing
     * shippingDestination is added (Google requires it for the shipping markup).
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function injectIntoOffer(array $offer, PromiseResult $promise, string $shippingCountry): array
    {
        if (isset($offer['shippingDetails']) && \is_array($offer['shippingDetails'])) {
            $deliveryTime = $offer['shippingDetails']['deliveryTime'] ?? ['@type' => 'ShippingDeliveryTime'];
            if (\is_array($deliveryTime)) {
                $handling = $this->handlingTime($promise);
                if ($handling !== null) {
                    // Replaces a handling time from the Shopware delivery time (e.g. 1–3 days): together with the
                    // transit time it must give the date the page shows, not a wider range.
                    $deliveryTime['handlingTime'] = $handling;
                }
                $deliveryTime['transitTime'] = $this->transitTime($promise);
                $offer['shippingDetails']['deliveryTime'] = $deliveryTime;
            }
            if (!isset($offer['shippingDetails']['shippingDestination'])) {
                $offer['shippingDetails']['shippingDestination'] = [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => $shippingCountry,
                ];
            }
        } else {
            $offer['shippingDetails'] = $this->shippingDetails($promise, $shippingCountry);
        }

        return $offer;
    }

    /**
     * @return array<string, mixed>
     */
    private function shippingDetails(PromiseResult $promise, string $shippingCountry): array
    {
        return [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => $shippingCountry,
            ],
            'deliveryTime' => array_filter([
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => $this->handlingTime($promise),
                'transitTime' => $this->transitTime($promise),
            ], static fn ($v) => $v !== null),
        ];
    }

    /**
     * Handling time from xl0g ({@see PromiseResult::handlingDays()}), or null without a usable ship date.
     *
     * @return array<string, mixed>|null
     */
    private function handlingTime(PromiseResult $promise): ?array
    {
        $days = $promise->handlingDays($this->now !== null ? ($this->now)() : null);
        if ($days === null) {
            return null;
        }

        return [
            '@type' => 'QuantitativeValue',
            'minValue' => $days,
            'maxValue' => $days,
            'unitCode' => 'DAY',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transitTime(PromiseResult $promise): array
    {
        $transit = $promise->transitDays ?? 1;

        return [
            '@type' => 'QuantitativeValue',
            'minValue' => $transit,
            'maxValue' => $transit,
            'unitCode' => 'DAY',
        ];
    }
}
