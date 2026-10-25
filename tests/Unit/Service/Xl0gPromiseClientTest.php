<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Xl0g\DeliveryPromise\Service\CountryTransitProvider;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * @covers \Xl0g\DeliveryPromise\Service\Xl0gPromiseClient
 */
class Xl0gPromiseClientTest extends TestCase
{
    private const PROMISE_JSON = [
        'promised_date' => '2026-07-24',
        'ship_date' => '2026-07-22',
        'transit_days' => 2,
        'carrier' => 'DHL',
        'cutoff_at' => '2026-07-22T14:00:00Z',
        'cutoff_time' => '14:00:00',
        'was_cutoff_missed' => false,
    ];

    public function testFetchReturnsPromiseAndSendsExpectedRequest(): void
    {
        $response = self::jsonResponse(self::PROMISE_JSON);
        $http = new MockHttpClient([$response]);
        $client = $this->client($http);

        $result = $client->fetch('standard', 'pdp');

        self::assertNotNull($result);
        self::assertSame('2026-07-24', $result->promisedDate);
        self::assertSame('DHL', $result->carrier);

        $url = $response->getRequestUrl();
        self::assertStringContainsString('/promise', $url);
        self::assertStringContainsString('format=data', $url);
        self::assertStringContainsString('shipping_method=standard', $url);
        self::assertStringContainsString('context=pdp', $url);

        $options = $response->getRequestOptions();
        self::assertSame(1.5, $options['timeout']);
        self::assertSame(2.0, $options['max_duration']);
        self::assertContains('X-API-Key: secret', $options['headers']);
    }

    public function testReportPageViewSendsUncachedRequestWithPageViewFields(): void
    {
        $first = self::jsonResponse(self::PROMISE_JSON);
        $second = self::jsonResponse(self::PROMISE_JSON);
        $http = new MockHttpClient([$first, $second]);
        $client = $this->client($http, reportPageViews: true);
        $pageView = ['page_sku' => '150-1', 'page_shop' => 'shop.example', 'visitor' => 'agent', 'visitor_agent' => 'GPTBot'];

        $client->reportPageView($pageView);
        $client->reportPageView($pageView);

        // Every view counts: no cache in between.
        self::assertSame(2, $http->getRequestsCount());
        $url = $first->getRequestUrl();
        foreach (['/promise', 'format=data', 'shipping_method=standard', 'context=pdp', 'page_sku=150-1', 'page_shop=shop.example', 'visitor=agent', 'visitor_agent=GPTBot'] as $part) {
            self::assertStringContainsString($part, $url);
        }
        $options = $first->getRequestOptions();
        self::assertSame(1.0, $options['timeout']);
        self::assertSame(1.0, $options['max_duration']);
        self::assertContains('X-API-Key: secret', $options['headers']);
    }

    public function testReportPageViewDoesNothingWhenSwitchedOffUnconfiguredOrEmpty(): void
    {
        $pageView = ['page_sku' => '150-1', 'visitor' => 'browser'];

        $off = new MockHttpClient([]);
        $this->client($off)->reportPageView($pageView);
        self::assertSame(0, $off->getRequestsCount());

        $unconfigured = new MockHttpClient([]);
        $this->client($unconfigured, configured: false, reportPageViews: true)->reportPageView($pageView);
        self::assertSame(0, $unconfigured->getRequestsCount());

        $empty = new MockHttpClient([]);
        $this->client($empty, reportPageViews: true)->reportPageView([]);
        self::assertSame(0, $empty->getRequestsCount());
    }

    public function testReportPageViewSwallowsErrors(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['error' => 'timeout'])]);

        $this->client($http, reportPageViews: true)->reportPageView(['page_sku' => '150-1', 'visitor' => 'bot']);

        self::assertSame(1, $http->getRequestsCount());
    }

    public function testFetchReturnsNullWithoutConfiguration(): void
    {
        $http = new MockHttpClient([]);
        $client = $this->client($http, configured: false);

        self::assertNull($client->fetch());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testFetchCachesSuccessfulResult(): void
    {
        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http);

        $first = $client->fetch('standard', 'pdp');
        $second = $client->fetch('standard', 'pdp');

        self::assertSame(1, $http->getRequestsCount());
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->promisedDate, $second->promisedDate);
    }

    public function testFetchCachesFailureAsNegativeResult(): void
    {
        $http = new MockHttpClient([new MockResponse('upstream error', ['http_code' => 500])]);
        $client = $this->client($http);

        self::assertNull($client->fetch('standard', 'pdp'));
        self::assertNull($client->fetch('standard', 'pdp'));
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testFetchReturnsNullOnBrokenJson(): void
    {
        $http = new MockHttpClient([new MockResponse(
            'kein json {',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
        )]);
        $client = $this->client($http);

        self::assertNull($client->fetch());
        // Broken answers are negatively cached like failures:
        self::assertNull($client->fetch());
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testFetchReturnsNullOnTransportError(): void
    {
        $http = new MockHttpClient(function (): never {
            throw new TransportException('connection timed out');
        });
        $client = $this->client($http);

        self::assertNull($client->fetch());
    }

    public function testFetchNormalizesShippingMethodForCacheAndRequest(): void
    {
        $response = self::jsonResponse(self::PROMISE_JSON);
        $http = new MockHttpClient([$response]);
        $client = $this->client($http);

        self::assertNotNull($client->fetch('Standard', 'pdp'));
        self::assertNotNull($client->fetch('standard', 'pdp'));

        // "Standard" and "standard" share one cache entry …
        self::assertSame(1, $http->getRequestsCount());
        // … and the API gets the normalised value:
        self::assertStringContainsString('shipping_method=standard', $response->getRequestUrl());
    }

    public function testFetchUsesSeparateCacheEntriesPerMethodAndContext(): void
    {
        $http = new MockHttpClient([
            self::jsonResponse(self::PROMISE_JSON),
            self::jsonResponse(['promised_date' => '2026-07-25'] + self::PROMISE_JSON),
        ]);
        $client = $this->client($http);

        $pdp = $client->fetch('standard', 'pdp');
        $checkout = $client->fetch('standard', 'checkout');

        self::assertSame(2, $http->getRequestsCount());
        self::assertNotNull($pdp);
        self::assertNotNull($checkout);
    }

    public function testFetchFallsBackToDirectRequestWhenCacheIsBroken(): void
    {
        $brokenCache = new class implements CacheInterface {
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \RuntimeException('cache backend down');
            }

            public function delete(string $key): bool
            {
                return false;
            }
        };

        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http, cache: $brokenCache);

        $result = $client->fetch();

        self::assertNotNull($result);
        self::assertSame('2026-07-24', $result->promisedDate);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testFetchAddsConfiguredCountryOffset(): void
    {
        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http, transitDays: "AT=2\nCH=4");

        $result = $client->fetch('standard', 'pdp', null, 'AT');

        // Base Fri 2026-07-24: +1 → (Sat/Sun skipped) Mon 27, +2 → Tue 28.
        self::assertNotNull($result);
        self::assertSame('2026-07-28', $result->promisedDate);
        self::assertSame(4, $result->transitDays);
        // Warehouse-side values stay unchanged:
        self::assertSame('2026-07-22', $result->shipDate);
        self::assertSame('2026-07-22T14:00:00Z', $result->cutoffAt);
    }

    public function testFetchReturnsBaseResultForUnconfiguredCountry(): void
    {
        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http, transitDays: "AT=2");

        // No entry for NL ⇒ API value unchanged (as DE) — do NOT hide.
        $result = $client->fetch('standard', 'pdp', null, 'NL');

        self::assertNotNull($result);
        self::assertSame('2026-07-24', $result->promisedDate);
        self::assertSame(2, $result->transitDays);
    }

    public function testFetchSharesOneCacheEntryAcrossCountries(): void
    {
        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http, transitDays: "AT=2");

        $de = $client->fetch('standard', 'pdp', null, 'DE');
        $at = $client->fetch('standard', 'pdp', null, 'AT');

        // The cache stays country-neutral — one API request serves all countries:
        self::assertSame(1, $http->getRequestsCount());
        self::assertNotNull($de);
        self::assertNotNull($at);
        self::assertSame('2026-07-24', $de->promisedDate);
        self::assertSame('2026-07-28', $at->promisedDate);
    }

    public function testFetchCountryOffsetWithoutWeekendSkip(): void
    {
        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http, transitDays: "AT=2", skipWeekends: false);

        $result = $client->fetch('standard', 'pdp', null, 'AT');

        // Fri 2026-07-24 + 2 calendar days = Sun 2026-07-26.
        self::assertNotNull($result);
        self::assertSame('2026-07-26', $result->promisedDate);
    }

    public function testFetchCapsCacheTtlAtCutoff(): void
    {
        // Cutoff in ~60 s ⇒ the TTL is capped from 300 to the time left.
        $cutoff = (new \DateTimeImmutable('+60 seconds'))->format(\DateTimeInterface::RFC3339);
        $http = new MockHttpClient([self::jsonResponse(['cutoff_at' => $cutoff] + self::PROMISE_JSON)]);
        $client = $this->client($http, cache: $cache = self::capturingCache());

        self::assertNotNull($client->fetch('standard', 'pdp'));
        self::assertGreaterThanOrEqual(55, $cache->lastTtl);
        self::assertLessThanOrEqual(60, $cache->lastTtl);
    }

    public function testFetchUsesShortTtlForAlreadyExpiredCutoff(): void
    {
        // PROMISE_JSON carries a cutoff long passed (engine rollover case).
        $http = new MockHttpClient([self::jsonResponse(self::PROMISE_JSON)]);
        $client = $this->client($http, cache: $cache = self::capturingCache());

        self::assertNotNull($client->fetch('standard', 'pdp'));
        self::assertSame(30, $cache->lastTtl);
    }

    public function testFetchKeepsDefaultTtlWithoutCutoff(): void
    {
        $json = self::PROMISE_JSON;
        unset($json['cutoff_at']);
        $http = new MockHttpClient([self::jsonResponse($json)]);
        $client = $this->client($http, cache: $cache = self::capturingCache());

        self::assertNotNull($client->fetch('standard', 'pdp'));
        self::assertSame(300, $cache->lastTtl);
    }

    public function testExcludedShippingMethodIdsNormalizesAndFiltersConfigValues(): void
    {
        $client = $this->client(new MockHttpClient([]), excludedShippingMethods: [
            '0190aaaabbbbccccddddeeeeffff0001',
            '  0190AAAABBBBCCCCDDDDEEEEFFFF0002 ', // case + whitespace ⇒ normalised
            'kein-uuid-wert',                       // invalid ⇒ ignored
            42,                                     // Nicht-String ⇒ ignoriert
        ]);

        self::assertSame(
            ['0190aaaabbbbccccddddeeeeffff0001', '0190aaaabbbbccccddddeeeeffff0002'],
            $client->excludedShippingMethodIds()
        );
    }

    public function testExcludedShippingMethodIdsEmptyWithoutOrWithBrokenConfig(): void
    {
        self::assertSame([], $this->client(new MockHttpClient([]))->excludedShippingMethodIds());
        self::assertSame(
            [],
            $this->client(new MockHttpClient([]), excludedShippingMethods: 'kein-array')->excludedShippingMethodIds()
        );
    }

    /**
     * Cache stub that runs the callback directly and records the TTL set via expiresAfter().
     */
    private static function capturingCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public mixed $lastTtl = null;

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                $self = $this;
                $item = new class($self) implements ItemInterface {
                    public function __construct(private object $capture)
                    {
                    }

                    public function expiresAfter(int|\DateInterval|null $time): static
                    {
                        $this->capture->lastTtl = $time;

                        return $this;
                    }

                    public function getKey(): string
                    {
                        return 'test';
                    }

                    public function get(): mixed
                    {
                        return null;
                    }

                    public function isHit(): bool
                    {
                        return false;
                    }

                    public function set(mixed $value): static
                    {
                        return $this;
                    }

                    public function expiresAt(?\DateTimeInterface $expiration): static
                    {
                        return $this;
                    }

                    public function tag(iterable|string $tags): static
                    {
                        return $this;
                    }

                    public function getMetadata(): array
                    {
                        return [];
                    }
                };

                return $callback($item);
            }

            public function delete(string $key): bool
            {
                return false;
            }
        };
    }

    public function testPublicFeedIsNotListedWithoutOptIn(): void
    {
        $client = $this->client(new MockHttpClient(), true, null, '', null, null, false, false);

        self::assertNull($client->publicFeedUrl());
    }

    public function testPublicFeedIsListedOnOptInWithValidBaseUrl(): void
    {
        $client = $this->client(new MockHttpClient(), true, null, '', null, null, false, true);

        self::assertSame('https://xl0g.test/tenant/api/ucp-feed', $client->publicFeedUrl());
    }

    public function testPublicFeedIsNotListedOnOptInWithInvalidOrMissingBaseUrl(): void
    {
        self::assertNull($this->client(new MockHttpClient(), true, null, '', null, null, false, true, 'not a url')->publicFeedUrl());
        self::assertNull($this->client(new MockHttpClient(), true, null, '', null, null, false, true, 'https://xl0g.test/t/api?x=1')->publicFeedUrl());
        self::assertNull($this->client(new MockHttpClient(), false, null, '', null, null, false, true)->publicFeedUrl());
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function jsonResponse(array $body): MockResponse
    {
        return new MockResponse(
            (string) json_encode($body),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
        );
    }

    private function client(
        MockHttpClient $http,
        bool $configured = true,
        ?CacheInterface $cache = null,
        string $transitDays = '',
        ?bool $skipWeekends = null,
        mixed $excludedShippingMethods = null,
        bool $reportPageViews = false,
        bool $listPublicFeed = false,
        ?string $apiBaseUrl = null
    ): Xl0gPromiseClient {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['Xl0gDeliveryPromise.config.apiBaseUrl', null, $configured ? ($apiBaseUrl ?? 'https://xl0g.test/tenant/api') : ''],
            ['Xl0gDeliveryPromise.config.apiKey', null, $configured ? 'secret' : ''],
            ['Xl0gDeliveryPromise.config.defaultShippingMethod', null, 'standard'],
            ['Xl0gDeliveryPromise.config.internationalTransitDays', null, $transitDays],
            ['Xl0gDeliveryPromise.config.internationalSkipWeekends', null, $skipWeekends],
            ['Xl0gDeliveryPromise.config.excludedShippingMethods', null, $excludedShippingMethods],
            ['Xl0gDeliveryPromise.config.reportPageViews', null, $reportPageViews],
            ['Xl0gDeliveryPromise.config.listPublicFeed', null, $listPublicFeed],
        ]);

        return new Xl0gPromiseClient(
            $systemConfig,
            $http,
            $cache ?? new ArrayAdapter(),
            new NullLogger(),
            new CountryTransitProvider($systemConfig, new NullLogger())
        );
    }
}
