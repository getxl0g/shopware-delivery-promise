<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Xl0g\DeliveryPromise\Service\ShippingMethodResolver;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * @covers \Xl0g\DeliveryPromise\Service\ShippingMethodResolver
 */
class ShippingMethodResolverTest extends TestCase
{
    public function testCheckoutUsesMappingFromSelectedShippingMethod(): void
    {
        $context = $this->context([ShippingMethodResolver::CUSTOM_FIELD_NAME => 'express']);

        self::assertSame('express', $this->resolver()->resolve($context, 'checkout'));
    }

    public function testMappingIsNormalizedToLowercaseAndTrimmed(): void
    {
        $context = $this->context([ShippingMethodResolver::CUSTOM_FIELD_NAME => '  Express ']);

        self::assertSame('express', $this->resolver()->resolve($context, 'checkout'));
    }

    public function testPdpAlwaysUsesConfiguredDefaultEvenWithMapping(): void
    {
        $context = $this->context([ShippingMethodResolver::CUSTOM_FIELD_NAME => 'express']);

        self::assertSame('standard', $this->resolver()->resolve($context, 'pdp'));
    }

    public function testMissingOrEmptyMappingFallsBackToConfiguredDefault(): void
    {
        self::assertSame('standard', $this->resolver()->resolve($this->context(null), 'checkout'));
        self::assertSame('standard', $this->resolver()->resolve($this->context([]), 'checkout'));
        self::assertSame('standard', $this->resolver()->resolve(
            $this->context([ShippingMethodResolver::CUSTOM_FIELD_NAME => '   ']),
            'checkout'
        ));
    }

    public function testInvalidMappingSlugFallsBackToConfiguredDefault(): void
    {
        $context = $this->context([ShippingMethodResolver::CUSTOM_FIELD_NAME => 'not a valid slug!']);

        self::assertSame('standard', $this->resolver()->resolve($context, 'checkout'));
    }

    public function testTranslatedCustomFieldsTakePrecedenceOverRaw(): void
    {
        $method = new ShippingMethodEntity();
        $method->setId('11111111111111111111111111111111');
        $method->setCustomFields([ShippingMethodResolver::CUSTOM_FIELD_NAME => 'raw-value']);
        $method->setTranslated(['customFields' => [ShippingMethodResolver::CUSTOM_FIELD_NAME => 'translated-value']]);

        self::assertSame('translated-value', $this->resolver()->resolve($this->contextFor($method), 'checkout'));
    }

    public function testRawCustomFieldsAreUsedWithoutTranslation(): void
    {
        $method = new ShippingMethodEntity();
        $method->setId('11111111111111111111111111111111');
        $method->setCustomFields([ShippingMethodResolver::CUSTOM_FIELD_NAME => 'raw-value']);

        self::assertSame('raw-value', $this->resolver()->resolve($this->contextFor($method), 'checkout'));
    }

    public function testProductUuidResolvesToMappingOfReferencedShippingMethod(): void
    {
        $id = Uuid::randomHex();
        $resolver = $this->resolver($this->shippingMethodRepository($id, ' Spedition '));

        self::assertSame('spedition', $resolver->resolveForProduct($id, $this->context(null), 'checkout'));
    }

    public function testProductUuidMappingIsUsedOnPdpToo(): void
    {
        $id = Uuid::randomHex();
        $resolver = $this->resolver($this->shippingMethodRepository($id, 'Express'));

        self::assertSame('express', $resolver->resolveForProduct($id, $this->context(null), 'pdp'));
    }

    public function testProductUuidLookupIsMemoizedPerResolver(): void
    {
        $id = Uuid::randomHex();
        // StaticEntityRepository throws on a second search that was not prepared —
        // so two calls with the same UUID may trigger only ONE query.
        $resolver = $this->resolver($this->shippingMethodRepository($id, 'Express'));
        $context = $this->context(null);

        self::assertSame('express', $resolver->resolveForProduct($id, $context, 'checkout'));
        self::assertSame('express', $resolver->resolveForProduct($id, $context, 'checkout'));
    }

    public function testUnknownProductUuidFallsBackToExistingLogic(): void
    {
        $resolver = $this->resolver(new StaticEntityRepository([new ShippingMethodCollection([])]));

        self::assertSame('standard', $resolver->resolveForProduct(Uuid::randomHex(), $this->context(null), 'checkout'));
    }

    public function testReferencedMethodWithoutMappingFallsBackToExistingLogic(): void
    {
        $id = Uuid::randomHex();
        $resolver = $this->resolver($this->shippingMethodRepository($id, null));

        self::assertSame('standard', $resolver->resolveForProduct($id, $this->context(null), 'checkout'));
    }

    public function testInvalidMappingSlugOfReferencedMethodFallsBackToExistingLogic(): void
    {
        $id = Uuid::randomHex();
        $resolver = $this->resolver($this->shippingMethodRepository($id, 'Spedition International'));

        self::assertSame('standard', $resolver->resolveForProduct($id, $this->context(null), 'checkout'));
    }

    public function testNonUuidProductValueIsTreatedAsTextSlug(): void
    {
        $context = $this->context(null);

        self::assertSame('spedition', $this->resolver()->resolveForProduct('  Spedition ', $context, 'checkout'));
        self::assertSame('standard', $this->resolver()->resolveForProduct('not a valid value!', $context, 'checkout'));
    }

    public function testMissingProductValueFallsBackToExistingLogic(): void
    {
        // checkout: the mapping of the selected shipping method applies
        $withMapping = $this->context([ShippingMethodResolver::CUSTOM_FIELD_NAME => 'express']);
        self::assertSame('express', $this->resolver()->resolveForProduct(null, $withMapping, 'checkout'));
        self::assertSame('express', $this->resolver()->resolveForProduct('   ', $withMapping, 'checkout'));
        self::assertSame('express', $this->resolver()->resolveForProduct([], $withMapping, 'checkout'));

        // pdp: config default
        self::assertSame('standard', $this->resolver()->resolveForProduct(null, $this->context(null), 'pdp'));
    }

    public function testArrayValueUsesFirstEntry(): void
    {
        $id = Uuid::randomHex();
        $resolver = $this->resolver($this->shippingMethodRepository($id, 'Express'));

        self::assertSame('express', $resolver->resolveForProduct([$id], $this->context(null), 'checkout'));
    }

    public function testProductUuidInExcludedConfigIsExcluded(): void
    {
        $id = Uuid::randomHex();
        $resolver = $this->resolver(excludedShippingMethodIds: [$id]);

        self::assertTrue($resolver->isProductShippingMethodExcluded($id, 'sc-1'));
        // Case and whitespace of the product value are normalised.
        self::assertTrue($resolver->isProductShippingMethodExcluded('  ' . strtoupper($id) . ' ', 'sc-1'));
        // Arrays (multi-select) ⇒ first value, as in resolveForProduct().
        self::assertTrue($resolver->isProductShippingMethodExcluded([$id], 'sc-1'));
    }

    public function testProductUuidNotInExcludedConfigIsNotExcluded(): void
    {
        $resolver = $this->resolver(excludedShippingMethodIds: [Uuid::randomHex()]);

        self::assertFalse($resolver->isProductShippingMethodExcluded(Uuid::randomHex(), 'sc-1'));
    }

    public function testEmptyProductValueOrEmptyConfigIsNeverExcluded(): void
    {
        $excluding = $this->resolver(excludedShippingMethodIds: [Uuid::randomHex()]);
        self::assertFalse($excluding->isProductShippingMethodExcluded(null, 'sc-1'));
        self::assertFalse($excluding->isProductShippingMethodExcluded('   ', 'sc-1'));
        self::assertFalse($excluding->isProductShippingMethodExcluded([], 'sc-1'));

        self::assertFalse($this->resolver()->isProductShippingMethodExcluded(Uuid::randomHex(), 'sc-1'));
    }

    public function testTextSlugIsExcludedWhenItMatchesMappingOfExcludedMethod(): void
    {
        // Free text slug (tolerated future field variant): excluded exactly when it matches the
        // xl0g mapping of an excluded shipping method.
        $id = Uuid::randomHex();
        $resolver = $this->resolver($this->shippingMethodRepository($id, ' Spedition '), [$id]);

        self::assertTrue($resolver->isProductShippingMethodExcluded('spedition', 'sc-1'));
        self::assertFalse($resolver->isProductShippingMethodExcluded('express', 'sc-1'));
    }

    public function testExclusionReadsConfigOnceAndSkipsItForEmptyValues(): void
    {
        $client = $this->createMock(Xl0gPromiseClient::class);
        // Memoised per sales channel: two checks ⇒ exactly ONE config access.
        $client->expects(self::once())->method('excludedShippingMethodIds')->with('sc-1')->willReturn([]);
        $resolver = new ShippingMethodResolver($client, new NullLogger(), new StaticEntityRepository([]));

        self::assertFalse($resolver->isProductShippingMethodExcluded(Uuid::randomHex(), 'sc-1'));
        self::assertFalse($resolver->isProductShippingMethodExcluded(Uuid::randomHex(), 'sc-1'));

        // Empty product value ⇒ no config access at all.
        $untouched = $this->createMock(Xl0gPromiseClient::class);
        $untouched->expects(self::never())->method('excludedShippingMethodIds');
        $resolver = new ShippingMethodResolver($untouched, new NullLogger(), new StaticEntityRepository([]));

        self::assertFalse($resolver->isProductShippingMethodExcluded(null, 'sc-1'));
    }

    /**
     * @param list<string> $excludedShippingMethodIds config "Excluded shipping methods"
     */
    private function resolver(
        ?EntityRepository $shippingMethodRepository = null,
        array $excludedShippingMethodIds = []
    ): ShippingMethodResolver {
        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->method('shippingMethod')->with('sc-1')->willReturn('standard');
        $client->method('excludedShippingMethodIds')->willReturn($excludedShippingMethodIds);

        return new ShippingMethodResolver(
            $client,
            new NullLogger(),
            $shippingMethodRepository ?? new StaticEntityRepository([]),
        );
    }

    /** Repository stub: shipping method $id with xl0g mapping $mapping (null = no mapping maintained). */
    private function shippingMethodRepository(string $id, ?string $mapping): EntityRepository
    {
        $method = new ShippingMethodEntity();
        $method->setId($id);
        if ($mapping !== null) {
            $method->setTranslated(['customFields' => [ShippingMethodResolver::CUSTOM_FIELD_NAME => $mapping]]);
        }

        return new StaticEntityRepository([new ShippingMethodCollection([$method])]);
    }

    /**
     * @param array<string, mixed>|null $customFields translated custom fields of the selected shipping method
     */
    private function context(?array $customFields): SalesChannelContext
    {
        $method = new ShippingMethodEntity();
        $method->setId('11111111111111111111111111111111');
        if ($customFields !== null) {
            $method->setTranslated(['customFields' => $customFields]);
        }

        return $this->contextFor($method);
    }

    private function contextFor(ShippingMethodEntity $method): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-1');
        $context->method('getShippingMethod')->willReturn($method);

        return $context;
    }
}
