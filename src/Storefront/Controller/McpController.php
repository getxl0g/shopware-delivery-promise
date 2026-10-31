<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xl0g\DeliveryPromise\Service\AgentDiscoveryBuilder;
use Xl0g\DeliveryPromise\Service\ShopMcpProxy;

/**
 * The shop's MCP server for AI assistants: `POST /mcp` (answered by xl0g for this tenant, no API key needed by the
 * caller) and its server card `GET /.well-known/mcp/server-card.json`. Both exist only while the shop MCP is offered
 * for the sales channel (switch on, key and API base URL configured); otherwise 404, so nothing is announced that
 * the shop does not deliver. The logic lives in {@see ShopMcpProxy}; this controller is the thin HTTP skin.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class McpController extends StorefrontController
{
    public function __construct(
        private readonly ShopMcpProxy $proxy,
        private readonly AgentDiscoveryBuilder $builder,
    ) {
    }

    #[Route(path: '/mcp', name: 'frontend.xl0g.mcp', methods: ['GET', 'POST'], defaults: ['_httpCache' => false])]
    public function mcp(Request $request): Response
    {
        $response = $this->proxy->respond($request, $request->attributes->get('sw-sales-channel-id'));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route(path: '/.well-known/mcp/server-card.json', name: 'frontend.xl0g.mcp_server_card', methods: ['GET'])]
    public function serverCard(Request $request): Response
    {
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        if (!$this->proxy->offers($salesChannelId)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $shopUrl = $request->getSchemeAndHttpHost();
        $response = new JsonResponse($this->builder->serverCard($shopUrl, $shopUrl . '/mcp'));
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
