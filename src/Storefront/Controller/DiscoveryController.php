<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Xl0g\DeliveryPromise\Service\AgentDiscoveryBuilder;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * B5: Agent-Discovery — `GET /llms.txt` + `GET /.well-known/xl0g-delivery.json`.
 *
 * Zeigt KI-Einkaufsagenten, wo die (öffentlichen) Liefer-Signale liegen: das Schema.org-JSON-LD auf
 * den Produktseiten. Discovery-Hälfte des agentic commerce; keine Auth nötig (nur öffentliche Hinweise,
 * kein API-Key). Der Inhalt kommt aus dem {@see AgentDiscoveryBuilder}.
 *
 * Beide Routen melden den TATSÄCHLICHEN Stand des JSON-LD-Schalters dieses Sales Channels. Steht er
 * aus, kündigt das Manifest keine Felder an, die der Shop nicht ausliefert.
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
            $this->builder->llmsTxt($request->getSchemeAndHttpHost(), $this->jsonLdEnabled($request)),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    #[Route(path: '/.well-known/xl0g-delivery.json', name: 'frontend.xl0g.well_known', methods: ['GET'])]
    public function wellKnown(Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->builder->manifest($request->getSchemeAndHttpHost(), $this->jsonLdEnabled($request))
        );
    }

    /**
     * Lässt sich der Sales Channel nicht auflösen (die beiden Routen liegen an der Domain-Wurzel),
     * ist das Attribut null — dann liest {@see Xl0gPromiseClient::isJsonLdEnabled()} den globalen
     * Wert statt eines Kanal-Werts. Das ist die richtige Antwort für „kein Kanal bekannt", kein Notnagel.
     */
    private function jsonLdEnabled(Request $request): bool
    {
        return $this->client->isJsonLdEnabled($request->attributes->get('sw-sales-channel-id'));
    }
}
