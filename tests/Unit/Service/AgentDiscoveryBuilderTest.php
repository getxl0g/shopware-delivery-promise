<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Xl0g\DeliveryPromise\Service\AgentDiscoveryBuilder;

/**
 * @covers \Xl0g\DeliveryPromise\Service\AgentDiscoveryBuilder
 */
class AgentDiscoveryBuilderTest extends TestCase
{
    public function testManifestContainsShopUrlAndSignals(): void
    {
        $m = (new AgentDiscoveryBuilder())->manifest('https://shop.example', true, false);

        self::assertSame('xl0g-agent-discovery', $m['protocol']);
        self::assertSame('https://shop.example', $m['shop']['url']);
        self::assertSame('https://shop.example/sitemap.xml', $m['delivery_intelligence']['sitemap']);
        self::assertSame(['https://schema.org/OfferShippingDetails'], $m['delivery_intelligence']['signals']);
        self::assertSame('discovery', $m['agentic_commerce']['role']);
    }

    public function testManifestIsJsonSerializable(): void
    {
        $json = json_encode((new AgentDiscoveryBuilder())->manifest('https://shop.example', true, false));

        self::assertIsString($json);
        self::assertStringContainsString('xl0g-agent-discovery', $json);
    }

    public function testLlmsTxtMentionsSitemapAndWellKnown(): void
    {
        $txt = (new AgentDiscoveryBuilder())->llmsTxt('https://shop.example', true, false);

        self::assertStringContainsString('https://shop.example/sitemap.xml', $txt);
        self::assertStringContainsString('https://shop.example/.well-known/xl0g-delivery.json', $txt);
        self::assertStringContainsString('JSON-LD', $txt);
    }

    /**
     * The core: without JSON-LD output the manifest must not announce the fields — otherwise it sends
     * agents on a search that comes up empty (the case that was noticed in a customer shop).
     */
    public function testManifestPromisesNoFieldsWhenJsonLdDisabled(): void
    {
        $m = (new AgentDiscoveryBuilder())->manifest('https://shop.example', false, false);

        self::assertSame([], $m['delivery_intelligence']['signals']);
        self::assertStringNotContainsString('x-xl0g-delivery-date', json_encode($m['delivery_intelligence']['signals']));
        self::assertStringContainsString('writes no JSON-LD', $m['delivery_intelligence']['where']);
        // The rest of the manifest remains usable unchanged.
        self::assertSame('https://shop.example/sitemap.xml', $m['delivery_intelligence']['sitemap']);
        self::assertSame('discovery', $m['agentic_commerce']['role']);
    }

    public function testLlmsTxtNamesNoFieldsWhenJsonLdDisabled(): void
    {
        $txt = (new AgentDiscoveryBuilder())->llmsTxt('https://shop.example', false, false);

        self::assertStringNotContainsString('x-xl0g-delivery-date', $txt);
        self::assertStringNotContainsString('x-xl0g-cutoff-at', $txt);
        self::assertStringContainsString('does not expose', $txt);
        // The sitemap remains a valid hint, even without JSON-LD.
        self::assertStringContainsString('https://shop.example/sitemap.xml', $txt);
    }

    /**
     * Even with JSON-LD switched on, the honesty guard applies: products without stock deliberately
     * carry no date, and the order cutoff is only present while one applies.
     */
    public function testEnabledTextsQualifyTheirOwnPromise(): void
    {
        $b = new AgentDiscoveryBuilder();

        self::assertStringContainsString('deliberately carry none', $b->manifest('https://shop.example', true, false)['delivery_intelligence']['where']);
        self::assertStringContainsString('only while an order cutoff applies', $b->manifest('https://shop.example', true, false)['delivery_intelligence']['where']);
        self::assertStringContainsString('deliberately carry none', $b->llmsTxt('https://shop.example', true, false));
    }

    /**
     * With Shopware's own product JSON-LD the plugin writes no x-xl0g-* fields — the manifest must not announce them,
     * but name what is there: handlingTime + transitTime with minValue = maxValue.
     */
    public function testCoreJsonLdAnnouncesShippingTimesInsteadOfExtraFields(): void
    {
        $b = new AgentDiscoveryBuilder();
        $m = $b->manifest('https://shop.example', true, true);
        $txt = $b->llmsTxt('https://shop.example', true, true);

        self::assertSame(['https://schema.org/OfferShippingDetails'], $m['delivery_intelligence']['signals']);
        self::assertStringContainsString('handlingTime and transitTime', $m['delivery_intelligence']['where']);
        self::assertStringContainsString('no x-xl0g-* fields', $m['delivery_intelligence']['where']);
        self::assertStringNotContainsString('x-xl0g-delivery-date', $txt);
        self::assertStringContainsString('minValue = maxValue', $txt);
        // Switched off stays switched off, core or not.
        self::assertSame([], $b->manifest('https://shop.example', false, true)['delivery_intelligence']['signals']);
        self::assertStringContainsString('does not expose', $b->llmsTxt('https://shop.example', false, true));
    }

    /**
     * The fields are not rendered in every case (an SEO plugin's markup can leave them out), so the manifest
     * lists them as optional, never as guaranteed signals, and says so.
     */
    public function testXl0gFieldsAreOptionalNeverGuaranteed(): void
    {
        $b = new AgentDiscoveryBuilder();
        $m = $b->manifest('https://shop.example', true, false);
        $txt = $b->llmsTxt('https://shop.example', true, false);

        self::assertNotContains('x-xl0g-delivery-date', $m['delivery_intelligence']['signals']);
        self::assertSame(['x-xl0g-delivery-date', 'x-xl0g-cutoff-at'], $m['delivery_intelligence']['optional_signals']);
        self::assertStringContainsString('not guaranteed', $m['delivery_intelligence']['where']);
        self::assertStringContainsString('NOT guaranteed', $txt);
        self::assertStringContainsString('Do not rely', $txt);
        self::assertStringNotContainsString('Read these to answer', $txt);
        // No optional fields where none are written (core JSON-LD) or JSON-LD is off.
        self::assertArrayNotHasKey('optional_signals', $b->manifest('https://shop.example', true, true));
        self::assertArrayNotHasKey('optional_signals', $b->manifest('https://shop.example', false, false));
    }

    /**
     * Not emitted (switch off, or connection not configured — the caller folds both into $jsonLdEnabled = false):
     * neither text mentions a field or the JSON-LD as a source; what is true is named — the visible element only when
     * the widget is on, the public feed only when listed. Emitted: the optional fields are named. Both states.
     */
    public function testFieldsAreNamedOnlyInTheEmittedStateAndTheOtherStateDescribesOnlyWhatIsTrue(): void
    {
        $b = new AgentDiscoveryBuilder();
        $feed = 'https://xl0g.test/t/api/ucp-feed';

        // State 1: JSON-LD emitted (own block / SEO plugin markup): optional fields named, hedged.
        $on = $b->manifest('https://shop.example', true, false, $feed, true);
        $onTxt = $b->llmsTxt('https://shop.example', true, false, $feed, true);
        self::assertSame(['x-xl0g-delivery-date', 'x-xl0g-cutoff-at'], $on['delivery_intelligence']['optional_signals']);
        self::assertStringContainsString('x-xl0g-delivery-date', $onTxt);
        self::assertStringContainsString('NOT guaranteed', $onTxt);

        // State 2a: not emitted, widget on, feed listed: only the element and the feed are described.
        $off = $b->manifest('https://shop.example', false, false, $feed, true);
        $offTxt = $b->llmsTxt('https://shop.example', false, false, $feed, true);
        self::assertSame([], $off['delivery_intelligence']['signals']);
        self::assertArrayNotHasKey('optional_signals', $off);
        self::assertStringNotContainsString('a precise delivery date', $offTxt);
        self::assertStringNotContainsString('Delivery data is public on the product pages as JSON-LD', $offTxt);
        self::assertStringContainsString('shows delivery dates to visitors on the product page', $offTxt);
        self::assertStringContainsString('data-promised-date', $offTxt);
        self::assertStringContainsString($feed, $offTxt);
        self::assertSame('[data-xl0g-delivery-promise]', $off['delivery_intelligence']['page_element']['selector']);
        self::assertSame($feed, $off['product_feed']['url']);
        self::assertStringContainsString('shown to visitors on the product page', $off['delivery_intelligence']['where']);
        // Nowhere does the not-emitted state call a field present (the only mentions are "no x-xl0g-* fields").
        self::assertStringNotContainsString('x-xl0g-delivery-date', json_encode($off));
        self::assertStringNotContainsString('x-xl0g-delivery-date', $offTxt);
        self::assertStringNotContainsString('x-xl0g-cutoff-at', json_encode($off));

        // State 2b: not emitted and no widget: no claim about dates shown on the page either.
        $bare = $b->manifest('https://shop.example', false, false);
        $bareTxt = $b->llmsTxt('https://shop.example', false, false);
        self::assertStringNotContainsString('shown to visitors', $bare['delivery_intelligence']['where']);
        self::assertStringNotContainsString('shows delivery dates to visitors', $bareTxt);
        self::assertStringContainsString('does not expose delivery dates', $bareTxt);
        self::assertArrayNotHasKey('page_element', $bare);
        self::assertArrayNotHasKey('product_feed', $bare);
        self::assertStringNotContainsString('x-xl0g-delivery-date', $bareTxt);
    }

    public function testWidgetElementIsNamedAsReliableSourceOnlyWhenTheWidgetIsOn(): void
    {
        $b = new AgentDiscoveryBuilder();

        foreach ([[true, false], [true, true], [false, false]] as [$jsonLd, $core]) {
            $m = $b->manifest('https://shop.example', $jsonLd, $core, null, true);
            $txt = $b->llmsTxt('https://shop.example', $jsonLd, $core, null, true);

            self::assertSame('[data-xl0g-delivery-promise]', $m['delivery_intelligence']['page_element']['selector']);
            self::assertSame('data-promised-date', $m['delivery_intelligence']['page_element']['delivery_date_attribute']);
            self::assertSame('data-cutoff-at', $m['delivery_intelligence']['page_element']['cutoff_attribute']);
            self::assertStringContainsString('Reliable source of the date', $txt);
            self::assertStringContainsString('<time datetime="YYYY-MM-DD">', $txt);
            self::assertStringContainsString('is not the delivery date', $txt);
            self::assertStringContainsString('data-promised-date', $txt);

            // Widget off (also the default): the element is not promised.
            $off = $b->manifest('https://shop.example', $jsonLd, $core, null, false);
            self::assertArrayNotHasKey('page_element', $off);
            self::assertArrayNotHasKey('page_element', $b->manifest('https://shop.example', $jsonLd, $core));
            self::assertStringNotContainsString('data-promised-date', $b->llmsTxt('https://shop.example', $jsonLd, $core, null, false));
            self::assertStringNotContainsString('data-promised-date', $b->llmsTxt('https://shop.example', $jsonLd, $core));
        }
    }

    /**
     * The shop of the original finding: JSON-LD on, widget on, third-party SEO markup. The text must neither
     * promise the fields as certain nor claim the date is "in every product's JSON-LD".
     */
    public function testNoBranchPromisesTheFieldsOnEveryPage(): void
    {
        $b = new AgentDiscoveryBuilder();

        foreach ([true, false] as $widget) {
            $txt = $b->llmsTxt('https://shop.example', true, false, null, $widget);
            self::assertStringNotContainsString('including a precise delivery date', $txt);
            self::assertStringNotContainsString('Each product detail page', $txt);
            self::assertStringContainsString('may embed', $txt);
        }
    }

    public function testFeedIsListedInLlmsTxtAndManifestWhenKnown(): void
    {
        $b = new AgentDiscoveryBuilder();
        $feed = 'https://xl0g.com/<tenant>/api/ucp-feed';

        $txt = $b->llmsTxt('https://shop.example', true, false, $feed);
        $m = $b->manifest('https://shop.example', true, false, $feed);

        self::assertStringContainsString('Product feed with delivery dates (JSON, xl0g schema): ' . $feed, $txt);
        self::assertSame($feed, $m['product_feed']['url']);
        self::assertSame('application/json', $m['product_feed']['format']);
        // An agent can find the delivery-promise fields without opening the feed first.
        self::assertStringContainsString('xl0gDelivery.promisedDate', $txt);
        self::assertStringContainsString('xl0gDelivery.cutoffAt', $txt);
        self::assertSame(
            ['promised_date' => 'xl0gDelivery.promisedDate', 'cutoff_at' => 'xl0gDelivery.cutoffAt'],
            $m['product_feed']['delivery_fields']
        );
        // Neutral wording: no protocol conformance is claimed.
        self::assertStringNotContainsStringIgnoringCase('UCP', $m['product_feed']['description']);
        self::assertStringNotContainsStringIgnoringCase('ACP-compliant', $txt);
        // Listed independently of the JSON-LD switch.
        self::assertStringContainsString($feed, $b->llmsTxt('https://shop.example', false, false, $feed));
        self::assertSame($feed, $b->manifest('https://shop.example', false, false, $feed)['product_feed']['url']);
    }

    public function testFeedIsNotListedWhenUnknown(): void
    {
        $b = new AgentDiscoveryBuilder();

        foreach ([null, ''] as $feed) {
            $txt = $b->llmsTxt('https://shop.example', true, false, $feed);
            $m = $b->manifest('https://shop.example', true, false, $feed);

            self::assertStringNotContainsString('Product feed', $txt);
            self::assertStringNotContainsString('ucp-feed', $txt);
            self::assertStringNotContainsString('xl0gDelivery', $txt);
            self::assertArrayNotHasKey('product_feed', $m);
        }
        // Default (parameter omitted) behaves like null.
        self::assertArrayNotHasKey('product_feed', $b->manifest('https://shop.example', true, false));
    }

    /**
     * @dataProvider feedUrlProvider
     */
    public function testFeedUrlIsDerivedFromApiBaseUrlOnlyWhenPlausible(string $apiBase, ?string $expected): void
    {
        self::assertSame($expected, AgentDiscoveryBuilder::feedUrlFromApiBase($apiBase));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function feedUrlProvider(): iterable
    {
        yield 'plain' => ['https://xl0g.com/<tenant>/api', 'https://xl0g.com/<tenant>/api/ucp-feed'];
        yield 'trailing slash' => ['https://xl0g.com/<tenant>/api/', 'https://xl0g.com/<tenant>/api/ucp-feed'];
        yield 'surrounding whitespace' => [" https://xl0g.com/<tenant>/api \n", 'https://xl0g.com/<tenant>/api/ucp-feed'];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'no scheme' => ['xl0g.com/<tenant>/api', null];
        yield 'other scheme' => ['ftp://xl0g.com/<tenant>/api', null];
        yield 'query string' => ['https://xl0g.com/<tenant>/api?x=1', null];
        yield 'inner whitespace' => ['https://xl0g.com/<tenant> /api', null];
    }

    /**
     * The shop MCP (plugin 0.8.0): address derived from the API base URL by swapping the last segment; anything that is
     * not a plain http(s) URL ending in /api yields null, so nothing is offered or listed by accident.
     */
    public function testMcpUrlFromApiBase(): void
    {
        self::assertSame('https://xl0g.test/tenant/mcp', AgentDiscoveryBuilder::mcpUrlFromApiBase('https://xl0g.test/tenant/api'));
        self::assertSame('https://xl0g.test/tenant/mcp', AgentDiscoveryBuilder::mcpUrlFromApiBase(' https://xl0g.test/tenant/api/ '));
        self::assertNull(AgentDiscoveryBuilder::mcpUrlFromApiBase(''));
        self::assertNull(AgentDiscoveryBuilder::mcpUrlFromApiBase('https://xl0g.test/tenant'));
        self::assertNull(AgentDiscoveryBuilder::mcpUrlFromApiBase('https://xl0g.test/tenant/api?x=1'));
        self::assertNull(AgentDiscoveryBuilder::mcpUrlFromApiBase('ftp://xl0g.test/tenant/api'));
        self::assertNull(AgentDiscoveryBuilder::mcpUrlFromApiBase('https://xl0g.test/tenant/api x'));
    }

    /** The MCP server is named in llms.txt and the manifest only when the shop offers it. */
    public function testMcpIsListedOnlyWhenOffered(): void
    {
        $b = new AgentDiscoveryBuilder();
        $mcp = 'https://shop.example/mcp';

        $txt = $b->llmsTxt('https://shop.example', true, false, null, false, $mcp);
        $m = $b->manifest('https://shop.example', true, false, null, false, $mcp);
        self::assertStringContainsString('- MCP server (no API key needed): https://shop.example/mcp', $txt);
        self::assertStringContainsString('delivery_date', $txt);
        self::assertStringContainsString('product_details', $txt);
        self::assertStringContainsString('https://shop.example/.well-known/mcp/server-card.json', $txt);
        self::assertSame($mcp, $m['mcp']['url']);
        self::assertSame('streamable-http', $m['mcp']['transport']);
        self::assertSame(['delivery_date', 'product_details'], $m['mcp']['tools']);
        self::assertSame('https://shop.example/.well-known/mcp/server-card.json', $m['mcp']['server_card']);

        $off = $b->llmsTxt('https://shop.example', true, false);
        self::assertStringNotContainsString('MCP', $off);
        self::assertArrayNotHasKey('mcp', $b->manifest('https://shop.example', true, false));
    }

    /** The server card: one remote without variables and without headers, so an assistant can connect as it is. */
    public function testServerCard(): void
    {
        $card = (new AgentDiscoveryBuilder())->serverCard('https://shop.example', 'https://shop.example/mcp');

        self::assertSame('https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json', $card['$schema']);
        self::assertSame('com.xl0g.shop/shop.example', $card['name']);
        self::assertStringStartsWith('shop.example — ', $card['title']);
        self::assertSame('https://shop.example/', $card['websiteUrl']);
        self::assertCount(1, $card['remotes']);
        self::assertSame('streamable-http', $card['remotes'][0]['type']);
        self::assertSame('https://shop.example/mcp', $card['remotes'][0]['url']);
        self::assertSame(['2026-07-28', '2025-06-18'], $card['remotes'][0]['supportedProtocolVersions']);
        self::assertArrayNotHasKey('variables', $card['remotes'][0]);
        self::assertArrayNotHasKey('headers', $card['remotes'][0]);
        self::assertIsString(json_encode($card));
    }
}
