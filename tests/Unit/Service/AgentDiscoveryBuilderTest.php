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
        $m = (new AgentDiscoveryBuilder())->manifest('https://shop.example', true);

        self::assertSame('xl0g-agent-discovery', $m['protocol']);
        self::assertSame('https://shop.example', $m['shop']['url']);
        self::assertSame('https://shop.example/sitemap.xml', $m['delivery_intelligence']['sitemap']);
        self::assertContains('x-xl0g-delivery-date', $m['delivery_intelligence']['signals']);
        self::assertSame('discovery', $m['agentic_commerce']['role']);
    }

    public function testManifestIsJsonSerializable(): void
    {
        $json = json_encode((new AgentDiscoveryBuilder())->manifest('https://shop.example', true));

        self::assertIsString($json);
        self::assertStringContainsString('xl0g-agent-discovery', $json);
    }

    public function testLlmsTxtMentionsSitemapAndWellKnown(): void
    {
        $txt = (new AgentDiscoveryBuilder())->llmsTxt('https://shop.example', true);

        self::assertStringContainsString('https://shop.example/sitemap.xml', $txt);
        self::assertStringContainsString('https://shop.example/.well-known/xl0g-delivery.json', $txt);
        self::assertStringContainsString('JSON-LD', $txt);
    }

    /**
     * Der Kern: ohne JSON-LD-Ausgabe darf das Manifest die Felder nicht ankündigen — sonst schickt es
     * Agenten auf eine Suche, die leer ausgeht (der Fall, der in einem Kundenshop aufgefallen ist).
     */
    public function testManifestPromisesNoFieldsWhenJsonLdDisabled(): void
    {
        $m = (new AgentDiscoveryBuilder())->manifest('https://shop.example', false);

        self::assertSame([], $m['delivery_intelligence']['signals']);
        self::assertStringNotContainsString('x-xl0g-delivery-date', json_encode($m['delivery_intelligence']['signals']));
        self::assertStringContainsString('switched off', $m['delivery_intelligence']['where']);
        // Der Rest des Manifests bleibt unverändert nutzbar.
        self::assertSame('https://shop.example/sitemap.xml', $m['delivery_intelligence']['sitemap']);
        self::assertSame('discovery', $m['agentic_commerce']['role']);
    }

    public function testLlmsTxtNamesNoFieldsWhenJsonLdDisabled(): void
    {
        $txt = (new AgentDiscoveryBuilder())->llmsTxt('https://shop.example', false);

        self::assertStringNotContainsString('x-xl0g-delivery-date', $txt);
        self::assertStringNotContainsString('x-xl0g-cutoff-at', $txt);
        self::assertStringContainsString('does not expose', $txt);
        // Die Sitemap bleibt ein gültiger Hinweis, auch ohne JSON-LD.
        self::assertStringContainsString('https://shop.example/sitemap.xml', $txt);
    }

    /**
     * Auch mit eingeschaltetem JSON-LD gilt der Ehrlichkeits-Riegel: Artikel ohne Bestand tragen
     * bewusst keinen Termin, und der Annahmeschluss steht nur an, solange einer greift.
     */
    public function testEnabledTextsQualifyTheirOwnPromise(): void
    {
        $b = new AgentDiscoveryBuilder();

        self::assertStringContainsString('deliberately carry none', $b->manifest('https://shop.example', true)['delivery_intelligence']['where']);
        self::assertStringContainsString('only while an order cutoff applies', $b->manifest('https://shop.example', true)['delivery_intelligence']['where']);
        self::assertStringContainsString('deliberately carry none', $b->llmsTxt('https://shop.example', true));
    }
}
