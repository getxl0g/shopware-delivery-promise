<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;

class VisitorClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function besucher(): iterable
    {
        yield 'Browser' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15', 'browser', ''];
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'agent', 'GPTBot'];
        yield 'ChatGPT-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0', 'agent', 'ChatGPT-User'];
        yield 'Claude-SearchBot vor ClaudeBot' => ['Mozilla/5.0 (compatible; Claude-SearchBot/1.0)', 'agent', 'Claude-SearchBot'];
        yield 'Googlebot ist Suchdienst' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'bot', ''];
        yield 'Applebot ist Suchdienst' => ['Mozilla/5.0 (Macintosh) AppleWebKit (KHTML, like Gecko) Applebot/0.1', 'bot', ''];
        yield 'namenloser Bot mit Mozilla' => ['Mozilla/5.0 (compatible; XyzBot/1.0)', 'bot', ''];
        yield 'curl' => ['curl/8.7.1', 'werkzeug', ''];
        yield 'Headless-Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/131.0 Safari/537.36', 'werkzeug', ''];
        yield 'leer' => ['', 'bot', ''];
    }

    #[DataProvider('besucher')]
    public function testClassify(string $ua, string $visitor, string $agent): void
    {
        $c = (new VisitorClassifier())->classify($ua);
        self::assertSame($visitor, $c['visitor']);
        self::assertSame($agent, $c['agent']);
    }

    public function testPageViewQueryCarriesNoUserAgent(): void
    {
        $ua = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';
        $q = (new VisitorClassifier())->pageViewQuery(' 150-1 ', 'WWW.Shop.Example', $ua);

        self::assertSame(['page_sku' => '150-1', 'visitor' => 'agent', 'page_shop' => 'www.shop.example', 'visitor_agent' => 'GPTBot'], $q);
        foreach ($q as $v) {
            self::assertStringNotContainsString('Mozilla', $v);
            self::assertStringNotContainsString('openai.com', $v);
        }
    }

    public function testPageViewQueryWithoutUsableSku(): void
    {
        $c = new VisitorClassifier();
        self::assertSame([], $c->pageViewQuery('', 'shop.example', 'Mozilla/5.0'));
        self::assertSame([], $c->pageViewQuery('150 1', 'shop.example', 'Mozilla/5.0'));
        self::assertSame([], $c->pageViewQuery(str_repeat('x', 65), 'shop.example', 'Mozilla/5.0'));
        self::assertSame([], $c->pageViewQuery('<b>', 'shop.example', 'Mozilla/5.0'));
    }

    public function testBrowserHasNoAgentField(): void
    {
        $q = (new VisitorClassifier())->pageViewQuery('A_1.2/3', '', 'Mozilla/5.0 Firefox/131.0');
        self::assertSame(['page_sku' => 'A_1.2/3', 'visitor' => 'browser'], $q);
    }

    /** Die Agentennamen müssen bei xl0g auf der Namensliste stehen — sonst zählt xl0g sie ohne Namen. */
    public function testAgentNamesAreOnXl0gAllowlist(): void
    {
        $xl0g = ['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'OAI-SearchBot', 'CCBot', 'Bytespider', 'Amazonbot',
            'ChatGPT-User', 'Claude-User', 'Claude-SearchBot', 'Perplexity-User', 'Applebot', 'meta-externalagent', 'DuckAssistBot',
            'MistralAI-User', 'Googlebot'];
        foreach (VisitorClassifier::AGENTS as $name) {
            self::assertContains($name, $xl0g);
        }
    }
}
