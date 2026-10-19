<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\StructuredDataBuilder;
use Xl0g\DeliveryPromise\Twig\StructuredDataTwigExtension;

/**
 * @covers \Xl0g\DeliveryPromise\Twig\StructuredDataTwigExtension
 */
class StructuredDataTwigExtensionTest extends TestCase
{
    public function testRegistersTheInjectFunction(): void
    {
        $names = array_map(
            static fn ($function) => $function->getName(),
            $this->extension()->getFunctions()
        );

        self::assertSame(['xl0g_inject_shipping_details'], $names);
    }

    public function testReturnsProductDataUntouchedWithoutUsableExtension(): void
    {
        $productData = ['@type' => 'Product', 'name' => 'Notizbuch A5'];

        self::assertSame($productData, $this->extension()->injectShippingDetails($productData, null));
        // Fallback extension (own block) carries only jsonLd, no date
        self::assertSame($productData, $this->extension()->injectShippingDetails($productData, new ArrayStruct(['jsonLd' => '{}'])));
        // Unusable date
        self::assertSame($productData, $this->extension()->injectShippingDetails($productData, new ArrayStruct(['promise' => ['ship_date' => '2026-07-14']])));
    }

    public function testNonArrayProductDataYieldsEmptyArray(): void
    {
        self::assertSame([], $this->extension()->injectShippingDetails(null, null));
    }

    public function testInjectsShippingDetailsFromPageExtension(): void
    {
        $promise = new PromiseResult('2026-07-15', '2026-07-14', 2, 'DHL', '2026-07-14T11:00:00+02:00', '11:00', false);
        $extension = new ArrayStruct(['promise' => $promise->toArray(), 'shippingCountry' => 'AT']);

        $result = $this->extension()->injectShippingDetails(
            ['@type' => 'Product', 'offers' => ['@type' => 'Offer', 'availability' => 'https://schema.org/InStock']],
            $extension
        );

        self::assertSame('https://schema.org/InStock', $result['offers']['availability']);
        self::assertSame('AT', $result['offers']['shippingDetails']['shippingDestination']['addressCountry']);
        self::assertSame(2, $result['offers']['shippingDetails']['deliveryTime']['transitTime']['minValue']);
        self::assertArrayNotHasKey('x-xl0g-delivery-date', $result);
    }

    public function testFallsBackToGermanyWithoutShippingCountry(): void
    {
        $extension = new ArrayStruct(['promise' => ['promised_date' => '2026-07-15']]);

        $result = $this->extension()->injectShippingDetails(['@type' => 'Product', 'offers' => ['@type' => 'Offer']], $extension);

        self::assertSame('DE', $result['offers']['shippingDetails']['shippingDestination']['addressCountry']);
    }

    private function extension(): StructuredDataTwigExtension
    {
        return new StructuredDataTwigExtension(new StructuredDataBuilder());
    }
}
