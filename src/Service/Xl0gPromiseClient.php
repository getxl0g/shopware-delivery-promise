<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client für die xl0g-Promise-API.
 *
 * Kapselt Config-Zugriff, den Aufruf `GET {apiBaseUrl}/promise?format=data` (Auth-Header `X-API-Key`)
 * und robuste Fehlerbehandlung. Der API-Key bleibt **server-seitig** (nie im Browser).
 *
 * Prinzip: xl0g liefert die ZAHLEN; Präsentation/Sprache macht das Storefront. Bei fehlender
 * Konfiguration, Timeout, Nicht-200 oder fehlendem Termin ⇒ `null` (Aufrufer blendet das Widget aus).
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
     * @param string      $shippingMethod z. B. "standard" (v0: eine repräsentative Versandart)
     * @param string      $context        "pdp" | "checkout" — steuert die xl0g-Vorlagenwahl
     * @param string|null $salesChannelId Config ist Sales-Channel-scoped (mehrsprachig/mehrkanalig)
     * @param array<string, string> $pageView  0.7.0: Meldung des Seitenaufrufs ({@see VisitorClassifier::pageViewQuery()}),
     *                                         nur wenn {@see isReportPageViewsEnabled()} — sonst leer
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
            // Nie das Storefront brechen: jeder Fehler ⇒ null ⇒ Widget versteckt.
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

    /** 0.7.0: Seitenaufrufe an xl0g melden (Artikelnummer und Art des Besuchers) — ab Werk aus. */
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
