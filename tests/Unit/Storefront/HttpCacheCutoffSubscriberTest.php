<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\RenderedPromiseCollector;
use Xl0g\DeliveryPromise\Storefront\Subscriber\HttpCacheCutoffSubscriber;

/**
 * @covers \Xl0g\DeliveryPromise\Storefront\Subscriber\HttpCacheCutoffSubscriber
 */
class HttpCacheCutoffSubscriberTest extends TestCase
{
    public function testCapsSharedMaxAgeToCutoff(): void
    {
        $collector = self::collectorWithCutoff('+120 seconds');
        $response = self::cacheableResponse(7200);

        (new HttpCacheCutoffSubscriber($collector))->onKernelResponse(self::event($response));

        $sMaxAge = (int) $response->headers->getCacheControlDirective('s-maxage');
        self::assertGreaterThanOrEqual(115, $sMaxAge);
        self::assertLessThanOrEqual(120, $sMaxAge);
    }

    public function testNeverExtendsSharedMaxAge(): void
    {
        $collector = self::collectorWithCutoff('+2 hours');
        $response = self::cacheableResponse(60);

        (new HttpCacheCutoffSubscriber($collector))->onKernelResponse(self::event($response));

        self::assertSame('60', $response->headers->getCacheControlDirective('s-maxage'));
    }

    public function testCapsToMinimumForExpiredCutoff(): void
    {
        $collector = self::collectorWithCutoff('-1 hour');
        $response = self::cacheableResponse(7200);

        (new HttpCacheCutoffSubscriber($collector))->onKernelResponse(self::event($response));

        self::assertSame('5', $response->headers->getCacheControlDirective('s-maxage'));
    }

    public function testLeavesResponsesWithoutSharedMaxAgeUntouched(): void
    {
        $collector = self::collectorWithCutoff('+120 seconds');
        $response = new Response();
        $before = $response->headers->get('Cache-Control');

        (new HttpCacheCutoffSubscriber($collector))->onKernelResponse(self::event($response));

        self::assertSame($before, $response->headers->get('Cache-Control'));
        // The collector is still cleared (no leaking into the next request):
        self::assertNull($collector->consume());
    }

    public function testDoesNothingWithoutRenderedPromise(): void
    {
        $response = self::cacheableResponse(7200);

        (new HttpCacheCutoffSubscriber(new RenderedPromiseCollector()))->onKernelResponse(self::event($response));

        self::assertSame('7200', $response->headers->getCacheControlDirective('s-maxage'));
    }

    public function testIgnoresSubRequests(): void
    {
        $collector = self::collectorWithCutoff('+120 seconds');
        $response = self::cacheableResponse(7200);

        (new HttpCacheCutoffSubscriber($collector))
            ->onKernelResponse(self::event($response, HttpKernelInterface::SUB_REQUEST));

        self::assertSame('7200', $response->headers->getCacheControlDirective('s-maxage'));
        // The cutoff stays noted for the main response:
        self::assertNotNull($collector->consume());
    }

    private static function collectorWithCutoff(string $modifier): RenderedPromiseCollector
    {
        $cutoff = (new \DateTimeImmutable($modifier))->format(\DateTimeInterface::RFC3339);
        $promise = PromiseResult::fromArray(['promised_date' => '2026-09-07', 'cutoff_at' => $cutoff]);
        self::assertNotNull($promise);

        $collector = new RenderedPromiseCollector();
        $collector->note($promise);

        return $collector;
    }

    private static function cacheableResponse(int $sMaxAge): Response
    {
        $response = new Response();
        $response->setPublic();
        $response->setSharedMaxAge($sMaxAge);

        return $response;
    }

    private function event(Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            $requestType,
            $response
        );
    }
}
