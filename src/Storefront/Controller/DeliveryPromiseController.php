<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * Dünner Storefront-Proxy: reicht die xl0g-ZAHLEN als JSON ans Storefront-JS.
 *
 * Die Logik (Config, HTTP, Fehler) liegt im {@see Xl0gPromiseClient}. Der API-Key bleibt
 * server-seitig; ans Storefront gehen nur die (öffentlichen) Liefer-Zahlen. Kein Termin ⇒ 204 ⇒
 * das JS blendet das Widget aus.
 *
 * 0.7.0: Ist „Seitenaufrufe an xl0g melden“ an, reicht das Widget die Artikelnummer mit (`sku`), und der Abruf meldet
 * sie zusammen mit der Art des Besuchers. Hier zählen nur Browser — Agenten und Roboter führen das Widget-JS meist
 * nicht aus und werden beim Erzeugen der Seite gezählt ({@see StructuredDataSubscriber}); so zählt jeder Besuch einmal.
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
