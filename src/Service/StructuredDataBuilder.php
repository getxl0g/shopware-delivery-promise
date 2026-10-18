<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Builds the Schema.org `Product`/`Offer`/`OfferShippingDetails` JSON-LD from the shop product data
 * + the xl0g {@see PromiseResult}. xl0g's contribution: the precise `shippingDetails` + the exact date
 * (`x-xl0g-delivery-date`). Pure function (no dependencies) → unit-testable (B6).
 */
class StructuredDataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        ?string $name,
        ?string $sku,
        ?string $price,
        ?string $currency,
        bool $inStock,
        PromiseResult $promise,
        string $shippingCountry = 'DE'
    ): array {
        $transit = $promise->transitDays ?? 1;

        $offer = [
            '@type' => 'Offer',
            'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'shippingDetails' => [
                '@type' => 'OfferShippingDetails',
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => $shippingCountry,
                ],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'transitTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => $transit,
                        'maxValue' => $transit,
                        'unitCode' => 'DAY',
                    ],
                ],
            ],
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
}
