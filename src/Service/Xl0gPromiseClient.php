<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the xl0g promise API.
 *
 * Encapsulates config access, the call `GET {apiBaseUrl}/promise?format=data` (auth header `X-API-Key`)
 * and robust error handling. The API key stays **server-side** (never in the browser).
 *
 * Principle: xl0g delivers the NUMBERS; presentation/language is the storefront's job. On missing
 * configuration, timeout, non-200 or missing date ⇒ `null` (the caller hides the widget).
 */
class Xl0gPromiseClient
{
    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function isConfigured(?string $salesChannelId = null): bool
    {
        return $this->baseUrl($salesChannelId) !== '' && $this->apiKey($salesChannelId) !== '';
    }

    /**
     * @param string      $shippingMethod e.g. "standard" (v0: one representative shipping method)
     * @param string      $context        "pdp" | "checkout" — controls the xl0g template choice
     * @param string|null $salesChannelId config is sales-channel scoped (multilingual/multichannel)
     * @param array<string, string> $pageView  0.7.0: page-view report ({@see VisitorClassifier::pageViewQuery()}),
     *                                         only if {@see isReportPageViewsEnabled()} — otherwise empty
     */
    public function fetch(
        string $shippingMethod = 'standard',
        string $context = 'pdp',
        ?string $salesChannelId = null,
        array $pageView = [],
        float $timeout = 3.0,
    ): ?PromiseResult {
        $base = $this->baseUrl($salesChannelId);
        $key = $this->apiKey($salesChannelId);
        if ($base === '' || $key === '') {
            return null;
        }

        $query = ['format' => 'data', 'shipping_method' => $shippingMethod];
        if ($context !== '') {
            $query['context'] = $context;
        }
        $query += $pageView;

        try {
            $response = $this->httpClient->request('GET', $base . '/promise', [
                'query' => $query,
                'headers' => ['X-API-Key' => $key, 'Accept' => 'application/json'],
                'timeout' => $timeout,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return PromiseResult::fromArray($response->toArray(false));
        } catch (\Throwable) {
            // Never break the storefront: any error ⇒ null ⇒ widget hidden.
            return null;
        }
    }

    public function shippingMethod(?string $salesChannelId = null): string
    {
        $v = (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.defaultShippingMethod', $salesChannelId);

        return $v !== '' ? $v : 'standard';
    }

    public function shippingCountry(?string $salesChannelId = null): string
    {
        $v = (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.shippingCountry', $salesChannelId);

        return $v !== '' ? $v : 'DE';
    }

    public function isJsonLdEnabled(?string $salesChannelId = null): bool
    {
        $v = $this->systemConfig->get('Xl0gDeliveryPromise.config.enableJsonLd', $salesChannelId);

        return $v === null ? true : (bool) $v; // Default an
    }

    /** 0.7.0: report page views to xl0g (product number and kind of visitor) — off by default. */
    public function isReportPageViewsEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get('Xl0gDeliveryPromise.config.reportPageViews', $salesChannelId);
    }

    public function isCheckoutWidgetEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get('Xl0gDeliveryPromise.config.enableCheckoutWidget', $salesChannelId);
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
