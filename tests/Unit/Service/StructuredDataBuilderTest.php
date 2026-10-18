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
        $jsonLd = (new StructuredDataBuilder())->build('Notizbuch A5', 'LT1917-A5', '19.90', 'EUR', true, $this->promise());

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

    public function testOutOfStockAvailability(): void
    {
        $jsonLd = (new StructuredDataBuilder())->build('X', 'SKU', '1.00', 'EUR', false, $this->promise());
        self::assertSame('https://schema.org/OutOfStock', $jsonLd['offers']['availability']);
    }

    public function testOmitsEmptyNamePriceAndSku(): void
    {
        $jsonLd = (new StructuredDataBuilder())->build(null, null, null, null, true, $this->promise());
        self::assertArrayNotHasKey('name', $jsonLd);
        self::assertArrayNotHasKey('sku', $jsonLd);
        self::assertArrayNotHasKey('price', $jsonLd['offers']);
        self::assertArrayNotHasKey('priceCurrency', $jsonLd['offers']);
    }

    public function testCustomShippingCountry(): void
    {
        $jsonLd = (new StructuredDataBuilder())->build('X', 'SKU', null, null, true, $this->promise(), 'AT');
        self::assertSame('AT', $jsonLd['offers']['shippingDetails']['shippingDestination']['addressCountry']);
    }

    public function testTransitFallsBackToOneAndOmitsNullCutoff(): void
    {
        $promise = new PromiseResult('2026-07-15', null, null, null, null, null, false);
        $jsonLd = (new StructuredDataBuilder())->build('X', 'SKU', null, null, true, $promise);

        self::assertSame(1, $jsonLd['offers']['shippingDetails']['deliveryTime']['transitTime']['minValue']);
        self::assertArrayNotHasKey('x-xl0g-cutoff-at', $jsonLd);
    }
}
