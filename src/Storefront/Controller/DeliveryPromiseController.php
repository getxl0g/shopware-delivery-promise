<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xl0g\DeliveryPromise\Service\ShippingMethodResolver;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * Thin storefront proxy: hands the xl0g NUMBERS to the storefront JS as JSON.
 *
 * The logic (config, HTTP, errors, cache) lives in the {@see Xl0gPromiseClient}. The API key stays
 * server-side; only the (public) delivery numbers go to the storefront. No date ⇒ 204 ⇒ the JS shows
 * the fallback or hides the widget.
 *
 * The answer depends only on sales channel + shipping method + context + delivery country ⇒ `_httpCache`
 * + a short `Cache-Control` max-age let Varnish/the browser absorb most widget requests. The delivery
 * country comes along as an explicit `country` query parameter (rendered server-side by the widget
 * template) — so the URL stays the cache key and `public` caching works per country; missing/invalid ⇒
 * delivery country from the SalesChannelContext.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class DeliveryPromiseController extends StorefrontController
{
    private const CONTEXTS = ['pdp', 'checkout'];

    public function __construct(
        private readonly Xl0gPromiseClient $client,
        private readonly ShippingMethodResolver $shippingMethodResolver,
        private readonly VisitorClassifier $classifier,
    ) {
    }

    #[Route(
        path: '/xl0g/delivery-promise',
        name: 'frontend.xl0g.delivery_promise',
        methods: ['GET'],
        defaults: ['XmlHttpRequest' => true, '_httpCache' => true]
    )]
    public function promise(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        $context = (string) $request->query->get('context', 'pdp');
        if (!\in_array($context, self::CONTEXTS, true)) {
            $context = 'pdp';
        }

        // Allow only harmless shipping method slugs — the value flows into the cache key and the API
        // call. Missing/invalid ⇒ resolution via the context: in the checkout the custom field mapping
        // of the selected shipping method, otherwise (or without a mapping) the configured default.
        $shippingMethod = strtolower((string) $request->query->get('shipping_method', ''));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $shippingMethod)) {
            $shippingMethod = $this->shippingMethodResolver->resolve($salesChannelContext, $context);
        }

        // Delivery country: strictly ISO-2 (limits the cache to at most 676 variants); invalid/missing ⇒
        // delivery country from the context (guest: default country, customer: active delivery address).
        $countryIso = strtoupper((string) $request->query->get('country', ''));
        if (!preg_match('/^[A-Z]{2}$/', $countryIso)) {
            $countryIso = (string) $salesChannelContext->getShippingLocation()->getCountry()->getIso();
        }

        $result = $this->client->fetch($shippingMethod, $context, $salesChannelId, $countryIso !== '' ? $countryIso : null);

        $response = $result === null
            ? new JsonResponse(new \stdClass(), JsonResponse::HTTP_NO_CONTENT)
            : new JsonResponse($result->toArray());

        // Successes may be cached publicly for a short time; failures even shorter, so that an API
        // disturbance does not stick longer than necessary. The success max-age is capped at
        // cutoff_at: Varnish AND the browser lose their JSON copy exactly when the date definitely
        // changes — the widget's refetch chain gets fresh data right after the cutoff (lower bound 5 s
        // coalesces parallel tabs; a cutoff already passed ⇒ 5 s too, until the engine completes the
        // rollover).
        $response->setPublic();
        $response->setMaxAge($result === null ? 30 : $result->cacheTtl(60, 5, 5));

        return $response;
    }

    /**
     * Page view beacon of the browser (switch “Report page views to xl0g”): the product page carries a hidden element
     * that sends one `navigator.sendBeacon()` here — the widget itself sends no request while the server-rendered date is
     * valid, and pages from the HTTP cache do not reach PHP. Only browsers count here; agents, search engines and tools
     * are counted when the page is generated ({@see \Xl0g\DeliveryPromise\Storefront\Subscriber\StructuredDataSubscriber}),
     * so every visit counts once. Never cached; always 204, whatever happens.
     */
    #[Route(
        path: '/xl0g/page-view',
        name: 'frontend.xl0g.page_view',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false]
    )]
    public function pageView(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $pageView = $this->classifier->pageViewQuery(
            (string) $request->request->get('sku', ''),
            $request->getHost(),
            (string) $request->headers->get('User-Agent', '')
        );
        if (($pageView['visitor'] ?? '') === VisitorClassifier::BROWSER) {
            $this->client->reportPageView($pageView, $salesChannelContext->getSalesChannelId());
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
