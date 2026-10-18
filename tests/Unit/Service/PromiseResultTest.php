<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Xl0g\DeliveryPromise\Service\PromiseResult;

/**
 * @covers \Xl0g\DeliveryPromise\Service\PromiseResult
 */
class PromiseResultTest extends TestCase
{
    public function testFromArrayBuildsFullResult(): void
    {
        $r = PromiseResult::fromArray([
            'promised_date' => '2026-07-15',
            'ship_date' => '2026-07-14',
            'transit_days' => 1,
            'carrier' => 'DHL',
            'cutoff_at' => '2026-07-14T11:00:00+02:00',
            'cutoff_time' => '11:00',
            'was_cutoff_missed' => false,
        ]);

        self::assertNotNull($r);
        self::assertSame('2026-07-15', $r->promisedDate);
        self::assertSame('2026-07-14', $r->shipDate);
        self::assertSame(1, $r->transitDays);
        self::assertSame('DHL', $r->carrier);
        self::assertSame('2026-07-14T11:00:00+02:00', $r->cutoffAt);
        self::assertSame('11:00', $r->cutoffTime);
        self::assertFalse($r->wasCutoffMissed);
    }

    public function testFromArrayReturnsNullWithoutPromisedDate(): void
    {
        self::assertNull(PromiseResult::fromArray([]));
        self::assertNull(PromiseResult::fromArray(['promised_date' => '']));
    }

    public function testFromArrayCoercesTypesAndDefaults(): void
    {
        $r = PromiseResult::fromArray([
            'promised_date' => '2026-08-01',
            'transit_days' => '3',    // String → int
            'was_cutoff_missed' => 1, // truthy → bool
        ]);

        self::assertNotNull($r);
        self::assertSame(3, $r->transitDays);
        self::assertTrue($r->wasCutoffMissed);
        self::assertNull($r->shipDate);
        self::assertNull($r->carrier);
        self::assertNull($r->cutoffAt);
    }

    public function testToArrayRoundTrips(): void
    {
        $data = [
            'promised_date' => '2026-07-15',
            'ship_date' => null,
            'transit_days' => 2,
            'carrier' => null,
            'cutoff_at' => null,
            'cutoff_time' => null,
            'was_cutoff_missed' => false,
        ];
        $r = PromiseResult::fromArray($data);

        self::assertNotNull($r);
        self::assertSame($data, $r->toArray());
    }
}
