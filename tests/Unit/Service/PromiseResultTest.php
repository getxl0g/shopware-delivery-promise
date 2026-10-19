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

    public function testWithAdditionalTransitDaysZeroReturnsSameInstance(): void
    {
        $r = PromiseResult::fromArray(['promised_date' => '2026-07-23']);

        self::assertNotNull($r);
        self::assertSame($r, $r->withAdditionalTransitDays(0));
        self::assertSame($r, $r->withAdditionalTransitDays(-1));
    }

    public function testWithAdditionalTransitDaysSkipsWeekends(): void
    {
        // 2026-07-23 = Thursday: +1 → Fri 24, +2 → (Sat/Sun skipped) Mon 27, +3 → Tue 28.
        $r = PromiseResult::fromArray(['promised_date' => '2026-07-23', 'transit_days' => 1]);

        self::assertNotNull($r);
        $shifted = $r->withAdditionalTransitDays(3);

        self::assertSame('2026-07-28', $shifted->promisedDate);
        self::assertSame(4, $shifted->transitDays);
    }

    public function testWithAdditionalTransitDaysWithoutWeekendSkip(): void
    {
        // Without the business day logic: Thursday +3 = Sunday.
        $r = PromiseResult::fromArray(['promised_date' => '2026-07-23']);

        self::assertNotNull($r);
        self::assertSame('2026-07-26', $r->withAdditionalTransitDays(3, skipWeekends: false)->promisedDate);
    }

    public function testWithAdditionalTransitDaysKeepsCutoffAndShipDate(): void
    {
        $r = PromiseResult::fromArray([
            'promised_date' => '2026-07-23',
            'ship_date' => '2026-07-22',
            'cutoff_at' => '2026-07-22T11:00:00+02:00',
            'cutoff_time' => '11:00',
            'was_cutoff_missed' => true,
        ]);

        self::assertNotNull($r);
        $shifted = $r->withAdditionalTransitDays(1);

        // The cutoff is on the warehouse side (independent of the country) — only the date moves:
        self::assertSame('2026-07-24', $shifted->promisedDate);
        self::assertSame('2026-07-22', $shifted->shipDate);
        self::assertSame('2026-07-22T11:00:00+02:00', $shifted->cutoffAt);
        self::assertSame('11:00', $shifted->cutoffTime);
        self::assertTrue($shifted->wasCutoffMissed);
        self::assertNull($shifted->transitDays);
    }

    public function testWithAdditionalTransitDaysLeavesUnparsableDateUntouched(): void
    {
        $r = PromiseResult::fromArray(['promised_date' => 'soon']);

        self::assertNotNull($r);
        self::assertSame($r, $r->withAdditionalTransitDays(3));
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

    public function testCacheTtlUsesDefaultWithoutCutoff(): void
    {
        $r = PromiseResult::fromArray(['promised_date' => '2026-07-15']);

        self::assertNotNull($r);
        self::assertSame(300, $r->cacheTtl(300, 5, 30, new \DateTimeImmutable('2026-07-14T10:00:00+02:00')));
    }

    public function testCacheTtlUsesDefaultForUnparsableCutoff(): void
    {
        self::assertSame(300, PromiseResult::ttlUntilCutoff('irgendwann', 300, 5, 30));
    }

    public function testCacheTtlCapsAtSecondsUntilCutoff(): void
    {
        $now = new \DateTimeImmutable('2026-07-14T13:58:00+02:00');

        // Cutoff in 120 s — closer than the default TTL ⇒ capped.
        self::assertSame(120, PromiseResult::ttlUntilCutoff('2026-07-14T14:00:00+02:00', 300, 5, 30, $now));
        // Cutoff further away than the default TTL ⇒ the default stays.
        self::assertSame(300, PromiseResult::ttlUntilCutoff('2026-07-14T18:00:00+02:00', 300, 5, 30, $now));
    }

    public function testCacheTtlRespectsMinimumJustBeforeCutoff(): void
    {
        $now = new \DateTimeImmutable('2026-07-14T13:59:58+02:00');

        self::assertSame(5, PromiseResult::ttlUntilCutoff('2026-07-14T14:00:00+02:00', 300, 5, 30, $now));
    }

    public function testCacheTtlUsesExpiredTtlAfterCutoff(): void
    {
        $now = new \DateTimeImmutable('2026-07-14T14:00:01+02:00');

        self::assertSame(30, PromiseResult::ttlUntilCutoff('2026-07-14T14:00:00+02:00', 300, 5, 30, $now));
        // Exactly on the cutoff counts as passed (<= 0 seconds left).
        self::assertSame(30, PromiseResult::ttlUntilCutoff('2026-07-14T14:00:00+02:00', 300, 5, 30, new \DateTimeImmutable('2026-07-14T14:00:00+02:00')));
    }

    public function testCacheTtlHandlesTimezoneOffsets(): void
    {
        // 14:00 +02:00 == 12:00 UTC — 60 s left from the UTC point of view.
        $now = new \DateTimeImmutable('2026-07-14T11:59:00+00:00');

        self::assertSame(60, PromiseResult::ttlUntilCutoff('2026-07-14T14:00:00+02:00', 300, 5, 30, $now));
    }

    public function testHandlingDaysCountsBusinessDaysUntilTheShipDate(): void
    {
        $promise = static fn (?string $ship, ?string $cutoff = '2026-10-20T14:00:00+02:00') => new PromiseResult('2026-10-22', $ship, 2, null, $cutoff, null, false);
        $tuesdayMorning = new \DateTimeImmutable('2026-10-20T07:00:00Z');

        self::assertSame(0, $promise('2026-10-20')->handlingDays($tuesdayMorning));          // ships today
        self::assertSame(1, $promise('2026-10-21')->handlingDays($tuesdayMorning));          // tomorrow
        self::assertSame(3, $promise('2026-10-23')->handlingDays($tuesdayMorning));          // Friday
        // Friday ⇒ Monday is one business day, the weekend does not count.
        self::assertSame(1, $promise('2026-10-26')->handlingDays(new \DateTimeImmutable('2026-10-23T10:00:00Z')));
        // “Today” in the warehouse’s time zone (from cutoff_at): 22:30 UTC on Tuesday is already Wednesday at +02:00.
        self::assertSame(0, $promise('2026-10-21')->handlingDays(new \DateTimeImmutable('2026-10-20T22:30:00Z')));
        self::assertSame(1, $promise('2026-10-21', null)->handlingDays(new \DateTimeImmutable('2026-10-20T22:30:00Z')));
        // No or stale ship date ⇒ null.
        self::assertNull($promise(null)->handlingDays($tuesdayMorning));
        self::assertNull($promise('2026-10-19')->handlingDays($tuesdayMorning));
        self::assertNull($promise('soon')->handlingDays($tuesdayMorning));
    }
}
