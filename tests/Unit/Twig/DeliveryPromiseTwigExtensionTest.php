<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\RenderedPromiseCollector;
use Xl0g\DeliveryPromise\Service\ShippingMethodResolver;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;
use Xl0g\DeliveryPromise\Twig\DeliveryPromiseTwigExtension;

/**
 * @covers \Xl0g\DeliveryPromise\Twig\DeliveryPromiseTwigExtension
 */
class DeliveryPromiseTwigExtensionTest extends TestCase
{
    public function testPromiseDelegatesToClientWithConfiguredDefaultMethod(): void
    {
        $result = PromiseResult::fromArray(['promised_date' => '2026-07-24']);

        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->method('shippingMethod')->with('sc-1')->willReturn('standard');
        $client->expects(self::once())
            ->method('fetch')
            ->with('standard', 'pdp', 'sc-1')
            ->willReturn($result);

        $ext = $this->extension($client);

        self::assertSame($result, $ext->promise('sc-1', '', 'pdp'));
    }

    public function testPromisePassesExplicitShippingMethodThrough(): void
    {
        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->expects(self::once())
            ->method('fetch')
            ->with('express', 'checkout', null)
            ->willReturn(null);

        $ext = $this->extension($client);

        self::assertNull($ext->promise(null, 'express'));
    }

    public function testPromiseWithFallbackReturnsPrimaryResultWithoutSecondFetch(): void
    {
        $result = PromiseResult::fromArray(['promised_date' => '2026-08-04']);

        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->expects(self::once())
            ->method('fetch')
            ->with('spedition', 'checkout', 'sc-1', 'DE')
            ->willReturn($result);

        $resolved = $this->extension($client)->promiseWithFallback('sc-1', 'spedition', 'standard', 'checkout', 'DE');

        self::assertSame(['shippingMethod' => 'spedition', 'promise' => $result], $resolved);
    }

    public function testPromiseWithFallbackFallsBackWhenPrimaryIsEmpty(): void
    {
        $result = PromiseResult::fromArray(['promised_date' => '2026-08-04']);

        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->expects(self::exactly(2))
            ->method('fetch')
            ->willReturnCallback(static fn (string $method) => $method === 'standard' ? $result : null);

        $resolved = $this->extension($client)->promiseWithFallback('sc-1', 'spedition', 'standard', 'checkout', 'DE');

        self::assertSame(['shippingMethod' => 'standard', 'promise' => $result], $resolved);
    }

    public function testPromiseWithFallbackKeepsPrimaryMethodWhenBothAreEmpty(): void
    {
        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->method('fetch')->willReturn(null);

        $resolved = $this->extension($client)->promiseWithFallback('sc-1', 'spedition', 'standard');

        self::assertSame(['shippingMethod' => 'spedition', 'promise' => null], $resolved);
    }

    public function testPromiseWithFallbackSkipsSecondFetchForIdenticalMethods(): void
    {
        $client = $this->createMock(Xl0gPromiseClient::class);
        $client->expects(self::once())->method('fetch')->willReturn(null);

        $resolved = $this->extension($client)->promiseWithFallback('sc-1', 'standard', 'standard');

        self::assertSame(['shippingMethod' => 'standard', 'promise' => null], $resolved);
    }

    public function testProductShippingMethodDelegatesToResolver(): void
    {
        $context = $this->createMock(SalesChannelContext::class);

        $resolver = $this->createMock(ShippingMethodResolver::class);
        $resolver->expects(self::once())
            ->method('resolveForProduct')
            ->with('Spedition', $context, 'checkout')
            ->willReturn('spedition');

        $ext = $this->extension(resolver: $resolver);

        self::assertSame('spedition', $ext->productShippingMethod($context, 'Spedition', 'checkout'));
    }

    public function testShippingMethodDelegatesToResolver(): void
    {
        $context = $this->createMock(SalesChannelContext::class);

        $resolver = $this->createMock(ShippingMethodResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with($context, 'checkout')
            ->willReturn('express');

        $ext = $this->extension(resolver: $resolver);

        self::assertSame('express', $ext->shippingMethod($context, 'checkout'));
    }

    public function testIsShippingMethodExcludedDelegatesToResolver(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-1');

        $resolver = $this->createMock(ShippingMethodResolver::class);
        $resolver->expects(self::once())
            ->method('isProductShippingMethodExcluded')
            ->with('11111111111111111111111111111111', 'sc-1')
            ->willReturn(true);

        $ext = $this->extension(resolver: $resolver);

        self::assertTrue($ext->isShippingMethodExcluded($context, '11111111111111111111111111111111'));
    }

    public function testCountdownComputesRemainingHoursAndMinutes(): void
    {
        $ext = $this->extension();

        $now = new \DateTimeImmutable('2026-07-22T10:13:00Z');
        $cd = $ext->countdown('2026-07-22T14:00:00Z', $now);

        self::assertSame(['hours' => 3, 'minutes' => 47], $cd);
    }

    public function testCountdownReturnsNullOnMissingExpiredOrBrokenCutoff(): void
    {
        $ext = $this->extension();
        $now = new \DateTimeImmutable('2026-07-22T15:00:00Z');

        self::assertNull($ext->countdown(null, $now));
        self::assertNull($ext->countdown('', $now));
        self::assertNull($ext->countdown('2026-07-22T14:00:00Z', $now)); // verstrichen
        self::assertNull($ext->countdown('kein datum', $now));
    }

    private function extension(?Xl0gPromiseClient $client = null, ?ShippingMethodResolver $resolver = null): DeliveryPromiseTwigExtension
    {
        return new DeliveryPromiseTwigExtension(
            $client ?? $this->createMock(Xl0gPromiseClient::class),
            $resolver ?? $this->createMock(ShippingMethodResolver::class),
            new RenderedPromiseCollector(),
        );
    }
}
