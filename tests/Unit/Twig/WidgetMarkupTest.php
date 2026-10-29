<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guard for the machine-readable date in the product page widget: the visible date is wrapped in
 * `<time datetime="YYYY-MM-DD">` (full ISO date, the visible text has no year) — in the Twig first render AND in
 * the JS re-render (they must stay byte-identical), and in the compiled bundle that is actually shipped.
 * No kernel and no Twig runtime needed, so this only checks the shared markup, not a rendered page.
 */
class WidgetMarkupTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../src/Resources';

    public function testTwigWrapsTheVisibleDateInATimeElementWithTheIsoDate(): void
    {
        $twig = $this->read('/views/storefront/component/xl0g/delivery-promise-widget-product.html.twig');

        self::assertStringContainsString('<time datetime="{{ xl0gPromise.promisedDate }}">', $twig);
        self::assertStringContainsString('</time>', $twig);
        // Only a plain YYYY-MM-DD gets the element (same guard as in the JS).
        self::assertStringContainsString("xl0gPromise.promisedDate matches '/^\\\\d{4}-\\\\d{2}-\\\\d{2}\$/'", $twig);
        // The compatibility attribute stays.
        self::assertStringContainsString('data-promised-date="{{ xl0gPromise.promisedDate }}"', $twig);
        // The countdown stays its own element, outside of the date.
        self::assertMatchesRegularExpression('#</b>.*<span class="xl0g-dp-countdown"#s', $twig);
    }

    public function testJsSourceAndShippedBundleRenderTheSameTimeElement(): void
    {
        $src = $this->read('/app/storefront/src/xl0g-delivery-promise/xl0g-delivery-promise.plugin.js');
        $dist = $this->read('/app/storefront/dist/storefront/js/xl0g-delivery-promise/xl0g-delivery-promise.js');

        foreach ([$src, $dist] as $js) {
            self::assertStringContainsString('<time datetime="${', $js);
            self::assertStringContainsString('.promised_date}">', $js);
            self::assertStringContainsString('xl0g-dp-date', $js);
            self::assertStringContainsString('^\\d{4}-\\d{2}-\\d{2}$', $js);
            self::assertStringContainsString('xl0g-dp-countdown', $js);
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents(self::RESOURCES . $path);
        self::assertNotFalse($content, $path);

        return $content;
    }
}
