<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\RenderedPromiseCollector;

/**
 * @covers \Xl0g\DeliveryPromise\Service\RenderedPromiseCollector
 */
class RenderedPromiseCollectorTest extends TestCase
{
    public function testKeepsEarliestCutoffAcrossNotes(): void
    {
        $collector = new RenderedPromiseCollector();
        $collector->note(self::promise('2026-09-04T18:00:00+02:00'));
        $collector->note(self::promise('2026-09-04T14:00:00+02:00'));
        $collector->note(self::promise('2026-09-04T16:00:00+02:00'));

        self::assertSame('2026-09-04T14:00:00+02:00', $collector->consume());
    }

    public function testIgnoresNullAndPromisesWithoutReadableCutoff(): void
    {
        $collector = new RenderedPromiseCollector();
        $collector->note(null);
        $collector->note(self::promise(null));
        $collector->note(self::promise('irgendwann'));

        self::assertNull($collector->consume());
    }

    public function testConsumeEmptiesTheCollector(): void
    {
        $collector = new RenderedPromiseCollector();
        $collector->note(self::promise('2026-09-04T14:00:00+02:00'));

        self::assertNotNull($collector->consume());
        // No leaking into the next request (long-running runtimes):
        self::assertNull($collector->consume());
    }

    private static function promise(?string $cutoffAt): PromiseResult
    {
        $result = PromiseResult::fromArray(['promised_date' => '2026-09-07', 'cutoff_at' => $cutoffAt]);
        self::assertNotNull($result);

        return $result;
    }
}
