<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Xl0g\DeliveryPromise\Service\ShopMcpProxy;

/**
 * @covers \Xl0g\DeliveryPromise\Service\ShopMcpProxy
 */
class ShopMcpProxyTest extends TestCase
{
    private const LIST = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';

    public function testOffersOnlyWhenSwitchedOnWithKeyAndDerivableAddress(): void
    {
        self::assertTrue($this->proxy(new MockHttpClient([]))->offers());
        self::assertFalse($this->proxy(new MockHttpClient([]), enabled: false)->offers());
        self::assertFalse($this->proxy(new MockHttpClient([]), key: '')->offers());
        self::assertFalse($this->proxy(new MockHttpClient([]), apiBaseUrl: 'https://xl0g.test/tenant')->offers());
        self::assertSame('https://xl0g.test/tenant/mcp', $this->proxy(new MockHttpClient([]))->upstreamUrl());
    }

    public function testNotOfferedIs404AndGetIs405(): void
    {
        $off = $this->proxy(new MockHttpClient([]), enabled: false);
        self::assertSame(404, $off->respond(self::request(self::LIST))->getStatusCode());

        $on = $this->proxy(new MockHttpClient([]));
        $get = $on->respond(Request::create('https://shop.example/mcp', 'GET'));
        self::assertSame(405, $get->getStatusCode());
        self::assertSame('POST', $get->headers->get('Allow'));
    }

    public function testForeignOriginIs403OwnOriginPasses(): void
    {
        $http = new MockHttpClient([self::json('{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}')]);
        $proxy = $this->proxy($http);

        $foreign = $proxy->respond(self::request(self::LIST, ['HTTP_ORIGIN' => 'https://evil.example']));
        self::assertSame(403, $foreign->getStatusCode());
        self::assertStringContainsString('origin not allowed', (string) $foreign->getContent());
        self::assertSame(0, $http->getRequestsCount());

        $own = $proxy->respond(self::request(self::LIST, ['HTTP_ORIGIN' => 'https://shop.example']));
        self::assertSame(200, $own->getStatusCode());
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testForwardsWithShopKeyAndStandardHeadersOnlyNeverTheCallersSecrets(): void
    {
        $upstream = self::json('{"jsonrpc":"2.0","id":7,"result":{"content":[{"type":"text","text":"Liefertermin für SKU 121459: promised_date=2026-11-04"}],"isError":false}}');
        $http = new MockHttpClient([$upstream]);
        $proxy = $this->proxy($http);
        $body = '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"delivery_date","arguments":{"sku":"121459"},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28"}}}';

        $response = $proxy->respond(self::request($body, [
            'HTTP_X_API_KEY' => 'callers-key',
            'HTTP_AUTHORIZATION' => 'Bearer callers-token',
            'HTTP_COOKIE' => 'session=abc',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => 'delivery_date',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('promised_date=2026-11-04', (string) $response->getContent());

        self::assertSame('https://xl0g.test/tenant/mcp', $upstream->getRequestUrl());
        self::assertSame('POST', $upstream->getRequestMethod());
        $options = $upstream->getRequestOptions();
        self::assertSame($body, $options['body']);
        self::assertSame(10.0, $options['timeout']);
        self::assertContains('X-API-Key: shop-mcp-key', $options['headers']);
        self::assertContains('MCP-Protocol-Version: 2026-07-28', $options['headers']);
        self::assertContains('Mcp-Method: tools/call', $options['headers']);
        self::assertContains('Mcp-Name: delivery_date', $options['headers']);
        foreach ($options['headers'] as $header) {
            self::assertStringNotContainsString('callers-key', $header);
            self::assertStringNotContainsString('callers-token', $header);
            self::assertStringNotContainsStringIgnoringCase('cookie', $header);
            self::assertStringNotContainsStringIgnoringCase('origin', $header);
        }
    }

    public function testOnlyThePublicToolsPassToolsCall(): void
    {
        $http = new MockHttpClient([]);
        $proxy = $this->proxy($http);
        foreach (['forecast_status', 'packing_result', 'master_data_check', 'product_ai_view', 'forecast_submit', 'nope'] as $tool) {
            $response = $proxy->respond(self::request('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"' . $tool . '","arguments":{}}}'));
            self::assertSame(200, $response->getStatusCode(), $tool);
            $decoded = json_decode((string) $response->getContent(), true);
            self::assertSame(-32602, $decoded['error']['code'], $tool);
            self::assertSame('unknown tool: ' . $tool, $decoded['error']['message'], $tool);
            self::assertSame(3, $decoded['id'], $tool);
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testToolsListIsFilteredToThePublicTools(): void
    {
        $http = new MockHttpClient([self::json('{"jsonrpc":"2.0","id":1,"result":{"tools":[' .
            '{"name":"delivery_date","description":"d"},{"name":"forecast_status","description":"f"},' .
            '{"name":"product_details","description":"p"},{"name":"master_data_status","description":"m"}],' .
            '"resultType":"complete"}}')]);
        $response = $this->proxy($http)->respond(self::request(self::LIST));

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertSame(['delivery_date', 'product_details'], array_column($decoded['result']['tools'], 'name'));
        self::assertSame('complete', $decoded['result']['resultType']);
    }

    public function testUpstreamErrorsPassThroughUnchanged(): void
    {
        $http = new MockHttpClient([self::json('{"jsonrpc":"2.0","id":1,"error":{"code":-32020,"message":"Header mismatch: Mcp-Method header is missing"}}', 400)]);
        $response = $this->proxy($http)->respond(self::request(self::LIST));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('-32020', (string) $response->getContent());
    }

    public function testUnreachableXl0gIsAToolErrorNeverA5xx(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['error' => 'connection refused'])]);
        $response = $this->proxy($http)->respond(self::request('{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"delivery_date","arguments":{"sku":"1"}}}'));

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertTrue($decoded['result']['isError']);
        self::assertSame(9, $decoded['id']);
        self::assertStringContainsString('not reachable', $decoded['result']['content'][0]['text']);
    }

    public function testParseErrorAndOversizedBody(): void
    {
        $http = new MockHttpClient([]);
        $proxy = $this->proxy($http);

        $parse = $proxy->respond(self::request('not json'));
        self::assertSame(400, $parse->getStatusCode());
        self::assertStringContainsString('-32700', (string) $parse->getContent());

        $big = $proxy->respond(self::request('{"a":"' . str_repeat('x', 17 * 1024) . '"}'));
        self::assertSame(400, $big->getStatusCode());
        self::assertStringContainsString('too large', (string) $big->getContent());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testRateLimitPerAddress(): void
    {
        $proxy = $this->proxy(new MockHttpClient([]));
        for ($i = 0; $i < ShopMcpProxy::LIMIT_PER_MINUTE; ++$i) {
            self::assertTrue($proxy->allow('203.0.113.7'), (string) $i);
        }
        self::assertFalse($proxy->allow('203.0.113.7'));
        self::assertTrue($proxy->allow('203.0.113.8'), 'another address has its own window');
        self::assertTrue($proxy->allow(''), 'unknown address is never blocked');

        $response = $proxy->respond(self::request(self::LIST, ['REMOTE_ADDR' => '203.0.113.7']));
        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('-32000', (string) $response->getContent());
    }

    public function testOriginRule(): void
    {
        self::assertTrue(ShopMcpProxy::originAllowed('', 'shop.example'));
        self::assertTrue(ShopMcpProxy::originAllowed('https://Shop.Example', 'shop.example'));
        self::assertFalse(ShopMcpProxy::originAllowed('https://evil.example', 'shop.example'));
        self::assertFalse(ShopMcpProxy::originAllowed('null', 'shop.example'));
    }

    private function proxy(MockHttpClient $http, bool $enabled = true, string $key = 'shop-mcp-key', ?string $apiBaseUrl = null): ShopMcpProxy
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['Xl0gDeliveryPromise.config.apiBaseUrl', null, $apiBaseUrl ?? 'https://xl0g.test/tenant/api'],
            ['Xl0gDeliveryPromise.config.shopMcpEnabled', null, $enabled],
            ['Xl0gDeliveryPromise.config.shopMcpApiKey', null, $key],
        ]);

        return new ShopMcpProxy($systemConfig, $http, new ArrayAdapter(), new NullLogger());
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(string $body, array $server = []): Request
    {
        return Request::create('https://shop.example/mcp', 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private static function json(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json']]);
    }
}
