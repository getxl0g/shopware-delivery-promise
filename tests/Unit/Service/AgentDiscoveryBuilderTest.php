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
        self::assertContains('x-xl0g-delivery-date', $m['delivery_intelligence']['signals']);
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
        self::assertStringContainsString('switched off', $m['delivery_intelligence']['where']);
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
}
