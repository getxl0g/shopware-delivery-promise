<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xl0g\DeliveryPromise\Service\AgentDiscoveryBuilder;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;
use Xl0g\DeliveryPromise\Storefront\Subscriber\StructuredDataSubscriber;

/**
 * B5: Agent-Discovery — `GET /llms.txt` + `GET /.well-known/xl0g-delivery.json`.
 *
 * Shows AI shopping agents where the (public) delivery signals are: the Schema.org JSON-LD on
 * the product pages. Discovery half of agentic commerce; no auth needed (public hints only,
 * no API key). The content comes from the {@see AgentDiscoveryBuilder}.
 *
 * Both routes report the ACTUAL state of this sales channel's JSON-LD switch. If it is
 * off, the manifest does not announce fields the shop does not deliver; with Shopware's own product JSON-LD it names
 * handlingTime + transitTime instead of the x-xl0g-* fields, which the plugin does not write there.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class DiscoveryController extends StorefrontController
{
    public function __construct(
        private readonly AgentDiscoveryBuilder $builder,
        private readonly Xl0gPromiseClient $client,
    ) {
    }

    #[Route(path: '/llms.txt', name: 'frontend.xl0g.llms_txt', methods: ['GET'])]
    public function llmsTxt(Request $request): Response
    {
        return new Response(
            $this->builder->llmsTxt($request->getSchemeAndHttpHost(), $this->jsonLdEnabled($request), StructuredDataSubscriber::isCoreJsonLdActive(), $this->feedUrl($request)),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    #[Route(path: '/.well-known/xl0g-delivery.json', name: 'frontend.xl0g.well_known', methods: ['GET'])]
    public function wellKnown(Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->builder->manifest($request->getSchemeAndHttpHost(), $this->jsonLdEnabled($request), StructuredDataSubscriber::isCoreJsonLdActive(), $this->feedUrl($request))
        );
    }

    /**
     * Address of the public product feed; null (not listed) unless the shop opted in and has a usable API base URL.
     */
    private function feedUrl(Request $request): ?string
    {
        return $this->client->publicFeedUrl($request->attributes->get('sw-sales-channel-id'));
    }

    /**
     * If the sales channel cannot be resolved (the two routes sit at the domain root),
     * the attribute is null — then {@see Xl0gPromiseClient::isJsonLdEnabled()} reads the global
     * value instead of a channel value. That is the right answer for "no channel known", not a stopgap.
     */
    private function jsonLdEnabled(Request $request): bool
    {
        return $this->client->isJsonLdEnabled($request->attributes->get('sw-sales-channel-id'));
    }
}
