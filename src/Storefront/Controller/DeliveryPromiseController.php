<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * Thin storefront proxy: hands the xl0g NUMBERS to the storefront JS as JSON.
 *
 * The logic (config, HTTP, errors) lives in the {@see Xl0gPromiseClient}. The API key stays
 * server-side; only the (public) delivery numbers go to the storefront. No date ⇒ 204 ⇒
 * the JS hides the widget.
 *
 * 0.7.0: If "Report page views to xl0g" is on, the widget passes the product number along (`sku`), and the request
 * reports it together with the kind of visitor. Only browsers count here — agents and robots mostly do not run the
 * widget JS and are counted when the page is generated ({@see StructuredDataSubscriber}); so every visit counts once.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class DeliveryPromiseController extends StorefrontController
{
    public function __construct(
        private readonly Xl0gPromiseClient $client,
        private readonly VisitorClassifier $classifier,
    ) {
    }

    #[Route(
        path: '/xl0g/delivery-promise',
        name: 'frontend.xl0g.delivery_promise',
        methods: ['GET'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function promise(Request $request): JsonResponse
    {
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        $pageView = [];
        if ($this->client->isReportPageViewsEnabled($salesChannelId)) {
            $q = $this->classifier->pageViewQuery(
                (string) $request->query->get('sku', ''),
                $request->getHost(),
                (string) $request->headers->get('User-Agent', '')
            );
            if (($q['visitor'] ?? '') === VisitorClassifier::BROWSER) {
                $pageView = $q;
            }
        }
        $result = $this->client->fetch(
            shippingMethod: (string) ($request->query->get('shipping_method') ?: $this->client->shippingMethod($salesChannelId)),
            context: (string) ($request->query->get('context') ?: 'pdp'),
            salesChannelId: $salesChannelId,
            pageView: $pageView,
        );

        if ($result === null) {
            return new JsonResponse(new \stdClass(), JsonResponse::HTTP_NO_CONTENT);
        }

        return new JsonResponse($result->toArray());
    }
}
