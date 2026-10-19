<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Immutable value object of an xl0g promise call — the NUMBERS (language-neutral), from the JSON of
 * `GET /api/promise?format=data`.
 */
final class PromiseResult
{
    public function __construct(
        public readonly string $promisedDate,   // ISO date, e.g. "2026-07-15"
        public readonly ?string $shipDate,
        public readonly ?int $transitDays,
        public readonly ?string $carrier,
        public readonly ?string $cutoffAt,       // RFC 3339 — anchor for the countdown
        public readonly ?string $cutoffTime,     // "11:00" — for display
        public readonly bool $wasCutoffMissed,
    ) {
    }

    /**
     * Builds from the xl0g JSON answer. Returns null if there is no usable date (the caller then hides
     * the widget — never a broken widget).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $promised = isset($data['promised_date']) ? (string) $data['promised_date'] : '';
        if ($promised === '') {
            return null;
        }

        return new self(
            promisedDate: $promised,
            shipDate: isset($data['ship_date']) ? (string) $data['ship_date'] : null,
            transitDays: isset($data['transit_days']) ? (int) $data['transit_days'] : null,
            carrier: isset($data['carrier']) ? (string) $data['carrier'] : null,
            cutoffAt: isset($data['cutoff_at']) ? (string) $data['cutoff_at'] : null,
            cutoffTime: isset($data['cutoff_time']) ? (string) $data['cutoff_time'] : null,
            wasCutoffMissed: (bool) ($data['was_cutoff_missed'] ?? false),
        );
    }

    /**
     * Copy with additional transit time (country offset): `promised_date` is moved by $days,
     * `transit_days` counted along. `ship_date`/`cutoff_*` stay unchanged — the cutoff is on the
     * warehouse side and therefore independent of the country; the countdown keeps ticking correctly.
     *
     * $skipWeekends: every added day counts Mon–Fri only, and the result never lands on Sat/Sun
     * ("Thursday +3" becomes Tuesday, not Sunday).
     *
     * Unreadable date ⇒ returned unchanged (better the base date than none — the widget is hidden only
     * on real API problems).
     */
    public function withAdditionalTransitDays(int $days, bool $skipWeekends = true): self
    {
        if ($days <= 0) {
            return $this;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->promisedDate);
        if ($date === false) {
            return $this;
        }

        for ($i = 0; $i < $days; $i++) {
            $date = $date->modify('+1 day');
            while ($skipWeekends && (int) $date->format('N') >= 6) {
                $date = $date->modify('+1 day');
            }
        }

        return new self(
            promisedDate: $date->format('Y-m-d'),
            shipDate: $this->shipDate,
            transitDays: $this->transitDays === null ? null : $this->transitDays + $days,
            carrier: $this->carrier,
            cutoffAt: $this->cutoffAt,
            cutoffTime: $this->cutoffTime,
            wasCutoffMissed: $this->wasCutoffMissed,
        );
    }

    /**
     * Handling time in business days (Mon–Fri) from today until `ship_date` — 0 if the order still ships today.
     * “Today” is taken in the time zone of `cutoff_at` (the warehouse’s), otherwise the server’s. Null without a
     * readable ship date or if it lies in the past (stale answer): then the caller writes no handling time.
     *
     * Together with the precise transit time this gives Google and assistants a window of exactly one day — the
     * same date the page shows — instead of the range from the Shopware delivery time.
     */
    public function handlingDays(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->shipDate === null) {
            return null;
        }
        $zone = null;
        if ($this->cutoffAt !== null && $this->cutoffAt !== '') {
            try {
                $zone = (new \DateTimeImmutable($this->cutoffAt))->getTimezone();
            } catch (\Exception) {
                $zone = null;
            }
        }
        $now = $now ?? new \DateTimeImmutable();
        $today = \DateTimeImmutable::createFromFormat('!Y-m-d', ($zone !== null ? $now->setTimezone($zone) : $now)->format('Y-m-d'));
        $ship = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->shipDate);
        if ($today === false || $ship === false || $ship < $today) {
            return null;
        }

        $days = 0;
        for ($d = $today; $d < $ship; ) {
            $d = $d->modify('+1 day');
            if ((int) $d->format('N') <= 5) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Cache lifetime "up to the cutoff at most": `cutoff_at` is the moment, known in advance, at which
     * the date definitely changes — no cache may live beyond it.
     *
     * - no/unreadable cutoff  ⇒ $defaultTtl (previous behaviour)
     * - cutoff in the future  ⇒ min($defaultTtl, seconds until the cutoff), never below $minTtl
     *                           ($minTtl protects the API/backend from zero TTLs and coalesces
     *                           parallel requests in the last minute)
     * - cutoff passed         ⇒ $expiredTtl (during the rollover the API may briefly still return the
     *                           old date — cache briefly, ask again soon)
     */
    public function cacheTtl(int $defaultTtl, int $minTtl, int $expiredTtl, ?\DateTimeImmutable $now = null): int
    {
        return self::ttlUntilCutoff($this->cutoffAt, $defaultTtl, $minTtl, $expiredTtl, $now);
    }

    /**
     * Static variant of {@see cacheTtl()} for callers that only have the cutoff string (e.g. the HTTP
     * cache subscriber). One implementation, one truth.
     */
    public static function ttlUntilCutoff(
        ?string $cutoffAt,
        int $defaultTtl,
        int $minTtl,
        int $expiredTtl,
        ?\DateTimeImmutable $now = null
    ): int {
        if ($cutoffAt === null || $cutoffAt === '') {
            return $defaultTtl;
        }

        try {
            $cutoff = new \DateTimeImmutable($cutoffAt);
        } catch (\Exception) {
            return $defaultTtl;
        }

        $remaining = $cutoff->getTimestamp() - ($now ?? new \DateTimeImmutable())->getTimestamp();
        if ($remaining <= 0) {
            return $expiredTtl;
        }

        return min($defaultTtl, max($minTtl, $remaining));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'promised_date' => $this->promisedDate,
            'ship_date' => $this->shipDate,
            'transit_days' => $this->transitDays,
            'carrier' => $this->carrier,
            'cutoff_at' => $this->cutoffAt,
            'cutoff_time' => $this->cutoffTime,
            'was_cutoff_missed' => $this->wasCutoffMissed,
        ];
    }
}
