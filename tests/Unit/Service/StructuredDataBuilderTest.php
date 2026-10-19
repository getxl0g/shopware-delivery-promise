<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\StructuredDataBuilder;

/**
 * @covers \Xl0g\DeliveryPromise\Service\StructuredDataBuilder
 */
class StructuredDataBuilderTest extends TestCase
{
    private function promise(): PromiseResult
    {
        return new PromiseResult('2026-07-15', '2026-07-14', 2, 'DHL', '2026-07-14T11:00:00+02:00', '11:00', false);
    }

    public function testBuildsProductWithOfferAndShippingDetails(): void
    {
        $jsonLd = (new StructuredDataBuilder())->build(
            'Notizbuch A5',
            'LT1917-A5',
            '19.90',
            'EUR',
            'https://schema.org/InStock',
            $this->promise()
        );

        self::assertSame('https://schema.org', $jsonLd['@context']);
        self::assertSame('Product', $jsonLd['@type']);
        self::assertSame('Notizbuch A5', $jsonLd['name']);
        self::assertSame('LT1917-A5', $jsonLd['sku']);
        self::assertSame('2026-07-15', $jsonLd['x-xl0g-delivery-date']);
        self::assertSame('2026-07-14T11:00:00+02:00', $jsonLd['x-xl0g-cutoff-at']);

        $offer = $jsonLd['offers'];
        self::assertSame('Offer', $offer['@type']);
        self::assertSame('19.90', $offer['price']);
        self::assertSame('EUR', $offer['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $offer['availability']);

        $shipping = $offer['shippingDetails'];
        self::assertSame('OfferShippingDetails', $shipping['@type']);
        self::assertSame('DE', $shipping['shippingDestination']['addressCountry']);
        self::assertSame(2, $shipping['deliveryTime']['transitTime']['minValue']);
        self::assertSame(2, $shipping['deliveryTime']['transitTime']['maxValue']);
    }

    public function testOmitsEmptyNamePriceAndSku(): void
    {
        $jsonLd = (new StructuredDataBuilder())->build(null, null, null, null, 'https://schema.org/InStock', $this->promise());
        self::assertArrayNotHasKey('name', $jsonLd);
        self::assertArrayNotHasKey('sku', $jsonLd);
        self::assertArrayNotHasKey('price', $jsonLd['offers']);
        self::assertArrayNotHasKey('priceCurrency', $jsonLd['offers']);
    }

    public function testCustomShippingCountry(): void
    {
        $jsonLd = (new StructuredDataBuilder())->build('X', 'SKU', null, null, 'https://schema.org/InStock', $this->promise(), 'AT');
        self::assertSame('AT', $jsonLd['offers']['shippingDetails']['shippingDestination']['addressCountry']);
    }

    public function testTransitFallsBackToOneAndOmitsNullCutoff(): void
    {
        $promise = new PromiseResult('2026-07-15', null, null, null, null, null, false);
        $jsonLd = (new StructuredDataBuilder())->build('X', 'SKU', null, null, 'https://schema.org/InStock', $promise);

        self::assertSame(1, $jsonLd['offers']['shippingDetails']['deliveryTime']['transitTime']['minValue']);
        self::assertArrayNotHasKey('x-xl0g-cutoff-at', $jsonLd);
    }

    /**
     * A closeout must never again be emitted as plain InStock —
     * the same derivation as the DreiSC defaults (LimitedAvailability/SoldOut).
     */
    public function testAvailabilityDerivation(): void
    {
        $builder = new StructuredDataBuilder();

        self::assertSame('https://schema.org/InStock', $builder->availability(false, 5));
        self::assertSame('https://schema.org/OutOfStock', $builder->availability(false, 0));
        self::assertSame('https://schema.org/LimitedAvailability', $builder->availability(true, 5));
        self::assertSame('https://schema.org/SoldOut', $builder->availability(true, 0));
    }

    /**
     * If a Product block (SEO plugin) is already present, the data is injected instead of building a
     * second block — its availability stays untouched, shippingRate/handlingTime
     * are kept, only the transitTime is replaced by the precise xl0g value.
     */
    public function testInjectIntoProductBlockKeepsAvailabilityAndMergesShippingDetails(): void
    {
        $blocks = [
            ['@type' => 'BreadcrumbList'],
            [
                '@context' => 'https://schema.org/',
                '@type' => 'Product',
                'name' => 'Cap',
                'offers' => [
                    [
                        '@type' => 'Offer',
                        'availability' => 'https://schema.org/LimitedAvailability',
                        'price' => 19.9,
                        'shippingDetails' => [
                            '@type' => 'OfferShippingDetails',
                            'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => 4.9, 'currency' => 'EUR'],
                            'deliveryTime' => [
                                '@type' => 'ShippingDeliveryTime',
                                'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'],
                                'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = (new StructuredDataBuilder())->injectIntoLdBlocks($blocks, $this->promise());

        self::assertNotNull($result);
        // Foreign blocks stay untouched
        self::assertSame(['@type' => 'BreadcrumbList'], $result[0]);

        $product = $result[1];
        self::assertSame('2026-07-15', $product['x-xl0g-delivery-date']);
        self::assertSame('2026-07-14T11:00:00+02:00', $product['x-xl0g-cutoff-at']);

        $offer = $product['offers'][0];
        // Availability stays solely the SEO plugin's business
        self::assertSame('https://schema.org/LimitedAvailability', $offer['availability']);
        // shippingRate + handlingTime stay, transitTime becomes precise
        self::assertSame(4.9, $offer['shippingDetails']['shippingRate']['value']);
        self::assertSame(1, $offer['shippingDetails']['deliveryTime']['handlingTime']['maxValue']);
        self::assertSame(2, $offer['shippingDetails']['deliveryTime']['transitTime']['minValue']);
        self::assertSame(2, $offer['shippingDetails']['deliveryTime']['transitTime']['maxValue']);
        // A missing shippingDestination is added (Google requires it for the shipping markup)
        self::assertSame('DE', $offer['shippingDetails']['shippingDestination']['addressCountry']);
    }

    public function testInjectIntoProductGroupReachesVariantsAndSingleOfferObjects(): void
    {
        $blocks = [
            [
                '@type' => 'ProductGroup',
                'offers' => [
                    ['@type' => 'AggregateOffer', 'availability' => 'https://schema.org/LimitedAvailability'],
                ],
                'hasVariant' => [
                    [
                        '@type' => 'Product',
                        // Single Offer object instead of a list (has @type directly)
                        'offers' => ['@type' => 'Offer', 'availability' => 'https://schema.org/LimitedAvailability'],
                    ],
                ],
            ],
        ];

        $result = (new StructuredDataBuilder())->injectIntoLdBlocks($blocks, $this->promise(), 'AT');

        self::assertNotNull($result);
        $group = $result[0];
        self::assertSame('2026-07-15', $group['x-xl0g-delivery-date']);

        // An AggregateOffer without shippingDetails gets the complete xl0g shippingDetails
        $aggregate = $group['offers'][0];
        self::assertSame('https://schema.org/LimitedAvailability', $aggregate['availability']);
        self::assertSame('AT', $aggregate['shippingDetails']['shippingDestination']['addressCountry']);
        self::assertSame(2, $aggregate['shippingDetails']['deliveryTime']['transitTime']['maxValue']);

        // Variant: x-xl0g fields + shippingDetails in the single Offer object
        $variant = $group['hasVariant'][0];
        self::assertSame('2026-07-15', $variant['x-xl0g-delivery-date']);
        self::assertSame('https://schema.org/LimitedAvailability', $variant['offers']['availability']);
        self::assertSame(2, $variant['offers']['shippingDetails']['deliveryTime']['transitTime']['minValue']);
    }

    public function testInjectReturnsNullWithoutProductBlock(): void
    {
        $blocks = [
            ['@type' => 'BreadcrumbList'],
            ['@type' => 'Organization'],
        ];

        self::assertNull((new StructuredDataBuilder())->injectIntoLdBlocks($blocks, $this->promise()));
        self::assertNull((new StructuredDataBuilder())->injectIntoLdBlocks([], $this->promise()));
    }

    /**
     * Core JSON-LD (feature flag JSON_LD_DATA) — the shippingDetails are merged into the
     * core product node: availability, seller and handlingTime (Shopware delivery time)
     * stay, transitTime becomes precise, the shippingDestination is added, and NO
     * x-xl0g extra fields are added (not Schema.org vocabulary, the validator stays clean).
     */
    public function testInjectIntoProductDataMergesShippingDetailsWithoutXl0gMeta(): void
    {
        $productData = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => 'https://shop.example/notizbuch/1001/#product',
            'name' => 'Notizbuch A5',
            'offers' => [
                '@type' => 'Offer',
                'price' => 19.0,
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'seller' => ['@type' => 'Organization', 'name' => 'Shop'],
                'shippingDetails' => [
                    '@type' => 'OfferShippingDetails',
                    'deliveryTime' => [
                        '@type' => 'ShippingDeliveryTime',
                        'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY'],
                    ],
                ],
            ],
        ];

        $result = (new StructuredDataBuilder())->injectIntoProductData($productData, $this->promise(), 'AT');

        self::assertArrayNotHasKey('x-xl0g-delivery-date', $result);
        self::assertArrayNotHasKey('x-xl0g-cutoff-at', $result);
        self::assertSame('https://shop.example/notizbuch/1001/#product', $result['@id']);
        self::assertSame('https://schema.org/InStock', $result['offers']['availability']);
        self::assertSame('Shop', $result['offers']['seller']['name']);

        $shipping = $result['offers']['shippingDetails'];
        self::assertSame('OfferShippingDetails', $shipping['@type']);
        self::assertSame(3, $shipping['deliveryTime']['handlingTime']['maxValue']);
        self::assertSame(2, $shipping['deliveryTime']['transitTime']['minValue']);
        self::assertSame(2, $shipping['deliveryTime']['transitTime']['maxValue']);
        self::assertSame('AT', $shipping['shippingDestination']['addressCountry']);
    }

    /**
     * With a ship date from xl0g the handling time comes from xl0g too: Monday 2026-07-13 10:00 (warehouse time),
     * shipping Tuesday ⇒ 1 business day; it replaces the range 1–3 from the Shopware delivery time, so handling +
     * transit give exactly the date the page shows. Applies to all three modes.
     */
    public function testHandlingTimeFromXl0gReplacesTheShopwareRange(): void
    {
        $builder = new StructuredDataBuilder(static fn () => new \DateTimeImmutable('2026-07-13T08:00:00Z'));
        $core = [
            '@type' => 'Product',
            'offers' => [
                '@type' => 'Offer',
                'shippingDetails' => [
                    '@type' => 'OfferShippingDetails',
                    'deliveryTime' => [
                        '@type' => 'ShippingDeliveryTime',
                        'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY'],
                    ],
                ],
            ],
        ];
        $exact = ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 1, 'unitCode' => 'DAY'];

        $merged = $builder->injectIntoProductData($core, $this->promise());
        self::assertSame($exact, $merged['offers']['shippingDetails']['deliveryTime']['handlingTime']);

        $seo = $builder->injectIntoLdBlocks([$core], $this->promise());
        self::assertNotNull($seo);
        self::assertSame($exact, $seo[0]['offers']['shippingDetails']['deliveryTime']['handlingTime']);

        $own = $builder->build('A', 'A-1', '1.00', 'EUR', 'https://schema.org/InStock', $this->promise());
        self::assertSame($exact, $own['offers']['shippingDetails']['deliveryTime']['handlingTime']);
        self::assertSame(2, $own['offers']['shippingDetails']['deliveryTime']['transitTime']['minValue']);
    }

    public function testWithoutUsableShipDateTheHandlingTimeStays(): void
    {
        // Ship date in the past (stale answer) ⇒ no handling time from xl0g; the Shopware range stays.
        $builder = new StructuredDataBuilder(static fn () => new \DateTimeImmutable('2026-07-20T08:00:00Z'));
        $core = ['@type' => 'Product', 'offers' => ['@type' => 'Offer', 'shippingDetails' => ['deliveryTime' => [
            'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY'],
        ]]]];

        $merged = $builder->injectIntoProductData($core, $this->promise());
        self::assertSame(3, $merged['offers']['shippingDetails']['deliveryTime']['handlingTime']['maxValue']);

        $own = $builder->build('A', 'A-1', null, null, 'https://schema.org/InStock', $this->promise());
        self::assertArrayNotHasKey('handlingTime', $own['offers']['shippingDetails']['deliveryTime']);
    }

    public function testInjectIntoProductDataHandlesProductGroupAndLeavesOtherTypesUntouched(): void
    {
        $group = [
            '@type' => 'ProductGroup',
            'hasVariant' => [
                ['@type' => 'Product', 'offers' => ['@type' => 'Offer', 'availability' => 'https://schema.org/InStock']],
            ],
        ];

        $result = (new StructuredDataBuilder())->injectIntoProductData($group, $this->promise());

        $variant = $result['hasVariant'][0];
        self::assertArrayNotHasKey('x-xl0g-delivery-date', $variant);
        self::assertSame('https://schema.org/InStock', $variant['offers']['availability']);
        self::assertSame('DE', $variant['offers']['shippingDetails']['shippingDestination']['addressCountry']);
        self::assertSame(2, $variant['offers']['shippingDetails']['deliveryTime']['transitTime']['maxValue']);

        $other = ['@type' => 'BreadcrumbList', 'itemListElement' => []];
        self::assertSame($other, (new StructuredDataBuilder())->injectIntoProductData($other, $this->promise()));
    }

    public function testInjectIntoProductDataCanKeepXl0gMetaOnRequest(): void
    {
        $result = (new StructuredDataBuilder())->injectIntoProductData(['@type' => 'Product'], $this->promise(), 'DE', true);

        self::assertSame('2026-07-15', $result['x-xl0g-delivery-date']);
        self::assertSame('2026-07-14T11:00:00+02:00', $result['x-xl0g-cutoff-at']);
    }
}
