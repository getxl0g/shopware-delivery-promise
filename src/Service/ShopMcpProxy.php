<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The shop's MCP server: `POST /mcp` on the shop domain, answered by the xl0g MCP server of the tenant.
 *
 * An AI assistant that visits the shop finds an MCP server without needing an API key. The plugin forwards each
 * request to `{tenant}/mcp` with the key configured for the shop MCP (kept server-side, like the promise key) and
 * returns xl0g's answer unchanged. The protocol itself (both versions, initialize, tools/list, tools/call) is spoken
 * by xl0g — this class does not reimplement it. What it does:
 *
 *   - only the two public tools pass (`delivery_date`, `product_details`): `tools/call` for any other name is
 *     refused here (-32602, as xl0g refuses unknown tools), and `tools/list` is filtered to the two names. The key
 *     should carry the permission `mcp:public`, which opens exactly these two tools on xl0g's side; the filter here
 *     is the second lock in case a broader key is configured;
 *   - the caller's own headers never travel: no X-API-Key, no Authorization, no Cookie, no Origin. Only the MCP
 *     standard headers (MCP-Protocol-Version, Mcp-Method, Mcp-Name) go along, so xl0g can check the newer protocol
 *     version exactly as it does for direct clients;
 *   - the Origin header is validated (none, or the shop's own host), the body is capped, and a per-address limit
 *     (60 requests per minute, counted in the object cache) keeps one client from spending the shop's quota;
 *   - a failure of xl0g is a tool result with isError:true, never a 5xx from the shop.
 *
 * Configuration per sales channel: `shopMcpEnabled` (off by default) and `shopMcpApiKey`; the address of the tenant's
 * MCP server is derived from the API base URL ({@see AgentDiscoveryBuilder::mcpUrlFromApiBase()}).
 */
class ShopMcpProxy
{
    /** The tools an assistant may use through the shop. Everything else stays behind the contractual access. */
    public const TOOLS = ['delivery_date', 'product_details'];

    /** Requests per address and minute. */
    public const LIMIT_PER_MINUTE = 60;

    private const MAX_BODY_BYTES = 16 * 1024;

    /** Time limit for the call to xl0g (seconds): a delivery date is computed synchronously, a product assessment is read. */
    private const TIMEOUT = 10.0;

    private const HEADERS_FORWARDED = ['MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name'];

    private const USER_AGENT = 'xl0g-shopware-delivery-promise (shop MCP)';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->systemConfig->get('Xl0gDeliveryPromise.config.shopMcpEnabled', $salesChannelId);
    }

    /** Address of the tenant's MCP server, derived from the API base URL; null if that is not usable. */
    public function upstreamUrl(?string $salesChannelId = null): ?string
    {
        return AgentDiscoveryBuilder::mcpUrlFromApiBase(
            (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.apiBaseUrl', $salesChannelId)
        );
    }

    /** Whether the shop offers the MCP server: switched on, key configured, address derivable. */
    public function offers(?string $salesChannelId = null): bool
    {
        return $this->isEnabled($salesChannelId) && $this->apiKey($salesChannelId) !== '' && $this->upstreamUrl($salesChannelId) !== null;
    }

    /**
     * Answers a request to `/mcp`. Order: offered (else 404), method (POST only, else 405), Origin (403), limit (429),
     * body (400 on unreadable JSON), then the message.
     */
    public function respond(Request $request, ?string $salesChannelId = null): Response
    {
        if (!$this->offers($salesChannelId)) {
            return self::plain(Response::HTTP_NOT_FOUND, 'Not Found');
        }
        if ($request->getMethod() !== Request::METHOD_POST) {
            $response = self::plain(Response::HTTP_METHOD_NOT_ALLOWED, '');
            $response->headers->set('Allow', 'POST');

            return $response;
        }
        if (!self::originAllowed((string) $request->headers->get('Origin', ''), $request->getHost())) {
            return self::rpcError(Response::HTTP_FORBIDDEN, null, -32600, 'origin not allowed');
        }
        if (!$this->allow($request->getClientIp() ?? '')) {
            return self::rpcError(Response::HTTP_TOO_MANY_REQUESTS, null, -32000, 'rate limited: ' . self::LIMIT_PER_MINUTE . ' requests per minute per address');
        }
        $raw = (string) $request->getContent();
        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            return self::rpcError(Response::HTTP_BAD_REQUEST, null, -32600, 'request body too large');
        }
        $message = json_decode($raw, true);
        if (!\is_array($message)) {
            return self::rpcError(Response::HTTP_BAD_REQUEST, null, -32700, 'parse error');
        }

        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $toolName = (string) ($message['params']['name'] ?? '');

        if ($method === 'tools/call' && !\in_array($toolName, self::TOOLS, true)) {
            // Refused here, before anything travels: the same answer xl0g gives for a tool it does not have.
            return self::rpcError(Response::HTTP_OK, $id, -32602, 'unknown tool: ' . $toolName);
        }

        $headers = [];
        foreach (self::HEADERS_FORWARDED as $name) {
            $value = (string) $request->headers->get($name, '');
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $this->forward($raw, $headers, $method, $id, $salesChannelId);
    }

    /**
     * Sends the message to the tenant's MCP server and returns status and body as they came back; `tools/list` is
     * filtered to the public tools. A transport failure becomes a tool result with isError:true.
     *
     * @param array<string, string> $headers
     */
    private function forward(string $raw, array $headers, string $method, mixed $id, ?string $salesChannelId): Response
    {
        $upstream = (string) $this->upstreamUrl($salesChannelId);
        try {
            $response = $this->httpClient->request('POST', $upstream, [
                'body' => $raw,
                'headers' => $headers + [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                    'X-API-Key' => $this->apiKey($salesChannelId),
                ],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('xl0g shop MCP: request to xl0g failed.', ['exception' => $e->getMessage()]);

            return self::toolError($id, 'The xl0g service is currently not reachable. Please try again in a minute.');
        }

        if ($method === 'tools/list' && $status === Response::HTTP_OK) {
            $body = self::filterToolList($body);
        }

        return new Response($body, $status, ['Content-Type' => 'application/json']);
    }

    /** Keeps only the public tools in a `tools/list` result; anything unreadable is returned as it came. */
    public static function filterToolList(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || !isset($decoded['result']['tools']) || !\is_array($decoded['result']['tools'])) {
            return $body;
        }
        $decoded['result']['tools'] = array_values(array_filter(
            $decoded['result']['tools'],
            static fn ($tool): bool => \is_array($tool) && \in_array($tool['name'] ?? '', self::TOOLS, true)
        ));

        return (string) json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** Fixed window per address, counted in the object cache; a cache failure never blocks a request. */
    public function allow(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        try {
            $item = $this->cache->getItem('xl0g-mcp-rl-' . md5($ip) . '-' . intdiv(time(), 60));
            $hits = (int) $item->get() + 1;
            $item->set($hits);
            $item->expiresAfter(120);
            $this->cache->save($item);

            return $hits <= self::LIMIT_PER_MINUTE;
        } catch (\Throwable $e) {
            $this->logger->debug('xl0g shop MCP: rate limit cache unavailable.', ['exception' => $e->getMessage()]);

            return true;
        }
    }

    /** No Origin (any client that is not a browser) or the shop's own host. */
    public static function originAllowed(string $origin, string $host): bool
    {
        if ($origin === '') {
            return true;
        }
        $originHost = parse_url($origin, \PHP_URL_HOST);

        return \is_string($originHost) && strcasecmp($originHost, $host) === 0;
    }

    private function apiKey(?string $salesChannelId): string
    {
        return trim((string) $this->systemConfig->get('Xl0gDeliveryPromise.config.shopMcpApiKey', $salesChannelId));
    }

    private static function plain(int $status, string $body): Response
    {
        $response = new Response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private static function rpcError(int $status, mixed $id, int $code, string $message): Response
    {
        $payload = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $payload['id'] = $id;
        }
        $payload['error'] = ['code' => $code, 'message' => $message];

        return new Response((string) json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json']);
    }

    private static function toolError(mixed $id, string $text): Response
    {
        $payload = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $payload['id'] = $id;
        }
        $payload['result'] = ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];

        return new Response((string) json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }
}
