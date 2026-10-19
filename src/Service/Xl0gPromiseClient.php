<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the xl0g promise API.
 *
 * Encapsulates config access, the call `GET {apiBaseUrl}/promise?format=data` (auth header `X-API-Key`)
 * and robust error handling. The API key stays **server-side** (never in the browser).
 *
 * Resilience: results are cached per (sales channel, shipping method, context) — successes for
 * SUCCESS_TTL, failures for FAILURE_TTL (negative cache). A disturbed or slow xl0g API therefore costs
 * the shop at most one short timeout per FAILURE_TTL instead of one per page view.
 *
 * Principle: xl0g delivers the NUMBERS; presentation/language is the storefront's job. On missing
 * configuration, timeout, non-200 or missing date ⇒ `null` (the caller hides the widget).
 *
 * International delivery addresses: the API knows no destination country — the configured country
 * offset ({@see CountryTransitProvider}) is added to the result AFTER the cache. Cache entry and API
 * call therefore stay country-neutral (one request serves all countries); countries not configured
 * get the API value unchanged (as DE).
 */
class Xl0gPromiseClient
{
    /**
     * The date depends on the shipping method (not on the product) and changes only at the cutoff/day
     * change — 5 minutes of cache are harmless and save almost all API calls. Capped at `cutoff_at`
     * ({@see PromiseResult::cacheTtl()}): the entry expires at the cutoff at the latest, and the first
     * access after it necessarily fetches the new date.
     */
    private const SUCCESS_TTL = 300;

    /** Negative cache: remember a failure briefly, but try again soon once xl0g is back. */
    private const FAILURE_TTL = 90;

    /** Lower bound of the cutoff capping — protects the API from zero TTLs right before the cutoff. */
    private const CUTOFF_MIN_TTL = 5;

    /** The answer already carries a passed cutoff (engine rollover): cache briefly, ask again soon. */
    private const EXPIRED_CUTOFF_TTL = 30;

    /** Idle/connect timeout in seconds — the call partly sits in the synchronous product page render path. */
    private const TIMEOUT = 1.5;

    /** Hard upper limit for the total duration of the request (seconds). */
    private const MAX_DURATION = 2.0;

    /** Time limit for a page view report (seconds) — it may sit in the product page render. */
    private const REPORT_TIMEOUT = 1.0;

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly CountryTransitProvider $countryTransit,
    ) {
    }

    public function isConfigured(?string $salesChannelId = null): bool
    {
        return $this->baseUrl($salesChannelId) !== '' && $this->apiKey($salesChannelId) !== '';
    }

    /**
     * @param string      $shippingMethod e.g. "standard"
     * @param string      $context        "pdp" | "checkout" — controls the xl0g template choice
     * @param string|null $salesChannelId config is sales-channel scoped (multilingual/multichannel)
     * @param string|null $countryIso     delivery country (ISO-2) — adds the configured country offset
     *                                    to the result; null/unknown ⇒ plain API value
     */
    public function fetch(
        string $shippingMethod = 'standard',
        string $context = 'pdp',
        ?string $salesChannelId = null,
        ?string $countryIso = null
    ): ?PromiseResult {
        $base = $this->baseUrl($salesChannelId);
        $key = $this->apiKey($salesChannelId);
        if ($base === '' || $key === '') {
            return null;
        }

        // Normalise, so that e.g. "Standard" (admin config) and "standard" (widget request) hit the
        // same cache entry instead of causing duplicate API calls.
        $shippingMethod = strtolower(trim($shippingMethod));
        if ($shippingMethod === '') {
            $shippingMethod = 'standard';
        }

        // md5 keeps the key compact and free of characters reserved in PSR-6 ({}()/\@:).
        $cacheKey = 'xl0g-promise-' . md5(implode('|', [$salesChannelId ?? '', $shippingMethod, $context]));

        try {
            $result = $this->cache->get(
                $cacheKey,
                function (ItemInterface $item) use ($base, $key, $shippingMethod, $context): ?PromiseResult {
                    $result = $this->requestPromise($base, $key, $shippingMethod, $context);
                    // TTL up to the cutoff at most (country offset irrelevant: it only moves
                    // promised_date, cutoff_at stays the same on the warehouse side).
                    $item->expiresAfter($result === null
                        ? self::FAILURE_TTL
                        : $result->cacheTtl(self::SUCCESS_TTL, self::CUTOFF_MIN_TTL, self::EXPIRED_CUTOFF_TTL));

                    return $result;
                }
            );
        } catch (\Throwable $e) {
            // Cache backend disturbed ⇒ ask directly without the cache, never break the storefront.
            $this->logger->warning('xl0g delivery promise: cache unavailable, falling back to direct request.', [
                'exception' => $e->getMessage(),
            ]);

            $result = $this->requestPromise($base, $key, $shippingMethod, $context);
        }

        return $this->withCountryOffset($result, $countryIso, $salesChannelId);
    }

    /**
     * Apply the country offset AFTER the (country-neutral) cache. No offset configured ⇒ base result
     * unchanged (as DE) — the widget is hidden only on real API problems.
     */
    private function withCountryOffset(
        ?PromiseResult $result,
        ?string $countryIso,
        ?string $salesChannelId
    ): ?PromiseResult {
        if ($result === null || $countryIso === null) {
            return $result;
        }

        $days = $this->countryTransit->additionalDays($countryIso, $salesChannelId);
        if ($days <= 0) {
            return $result;
        }

        return $result->withAdditionalTransitDays($days, $this->countryTransit->skipWeekends($salesChannelId));
    }

    public function shippingMethod(?string $salesChannelId = null): string
    {
        $v = (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.defaultShippingMethod', $salesChannelId);

        return $v !== '' ? $v : 'standard';
    }

    /**
     * IDs of the Shopware shipping methods excluded in the config (multi-select "Excluded shipping
     * methods", sales-channel scoped), normalised to lower case and filtered to valid UUIDs. Products
     * with one of these shipping methods in the product custom field get no xl0g delivery promise
     * (evaluation: {@see ShippingMethodResolver::isProductShippingMethodExcluded()}).
     *
     * @return list<string>
     */
    public function excludedShippingMethodIds(?string $salesChannelId = null): array
    {
        $raw = $this->systemConfig->get('Xl0gDeliveryPromise.config.excludedShippingMethods', $salesChannelId);
        if (!\is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (!\is_string($id)) {
                continue;
            }
            $id = strtolower(trim($id));
            if (Uuid::isValid($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function shippingCountry(?string $salesChannelId = null): string
    {
        $v = (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.shippingCountry', $salesChannelId);

        return $v !== '' ? $v : 'DE';
    }

    public function isJsonLdEnabled(?string $salesChannelId = null): bool
    {
        $v = $this->systemConfig->get('Xl0gDeliveryPromise.config.enableJsonLd', $salesChannelId);

        return $v === null ? true : (bool) $v; // default on
    }

    public function isReportPageViewsEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get('Xl0gDeliveryPromise.config.reportPageViews', $salesChannelId);
    }

    /**
     * Reports one product page view to xl0g ({@see VisitorClassifier::pageViewQuery()}): the request that otherwise
     * fetches the date, with the page view fields attached — always uncached, because every view counts, and with a
     * short time limit (REPORT_TIMEOUT), because it may sit in the page render. The answer is not needed; any error is
     * swallowed (debug log only — a report is not worth a warning per page view). Does nothing if the switch
     * “Report page views to xl0g” is off, the connection is not configured or $pageView is empty.
     *
     * @param array<string, string> $pageView
     */
    public function reportPageView(array $pageView, ?string $salesChannelId = null): void
    {
        if ($pageView === [] || !$this->isReportPageViewsEnabled($salesChannelId)) {
            return;
        }
        $base = $this->baseUrl($salesChannelId);
        $apiKey = $this->apiKey($salesChannelId);
        if ($base === '' || $apiKey === '') {
            return;
        }

        try {
            $response = $this->httpClient->request('GET', $base . '/promise', [
                'query' => ['format' => 'data', 'shipping_method' => $this->shippingMethod($salesChannelId), 'context' => 'pdp'] + $pageView,
                'headers' => ['X-API-Key' => $apiKey, 'Accept' => 'application/json'],
                'timeout' => self::REPORT_TIMEOUT,
                'max_duration' => self::REPORT_TIMEOUT,
            ]);
            $response->getStatusCode(); // complete the request within the time limit, not in the destructor
        } catch (\Throwable $e) {
            $this->logger->debug('xl0g delivery promise: page view report failed.', ['exception' => $e->getMessage()]);
        }
    }

    private function requestPromise(string $base, string $apiKey, string $shippingMethod, string $context): ?PromiseResult
    {
        $query = ['format' => 'data', 'shipping_method' => $shippingMethod];
        if ($context !== '') {
            $query['context'] = $context;
        }

        try {
            $response = $this->httpClient->request('GET', $base . '/promise', [
                'query' => $query,
                'headers' => ['X-API-Key' => $apiKey, 'Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger->warning('xl0g delivery promise: API returned non-200 status.', [
                    'status' => $status,
                    'shippingMethod' => $shippingMethod,
                    'context' => $context,
                ]);

                return null;
            }

            return PromiseResult::fromArray($response->toArray(false));
        } catch (\Throwable $e) {
            // Never break the storefront: any error ⇒ null ⇒ widget hidden/fallback.
            // The negative cache throttles this log line to about once per FAILURE_TTL.
            $this->logger->warning('xl0g delivery promise: API request failed.', [
                'exception' => $e->getMessage(),
                'shippingMethod' => $shippingMethod,
                'context' => $context,
            ]);

            return null;
        }
    }

    private function baseUrl(?string $salesChannelId): string
    {
        return rtrim((string) $this->systemConfig->get('Xl0gDeliveryPromise.config.apiBaseUrl', $salesChannelId), '/');
    }

    private function apiKey(?string $salesChannelId): string
    {
        return (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.apiKey', $salesChannelId);
    }
}
