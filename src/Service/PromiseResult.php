<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Immutable value object of an xl0g promise call — the NUMBERS (language-neutral),
 * from the JSON of `GET /api/promise?format=data`.
 */
final class PromiseResult
{
    public function __construct(
        public readonly string $promisedDate,   // ISO date, e.g. "2026-07-15"
        public readonly ?string $shipDate,
        public readonly ?int $transitDays,
        public readonly ?string $carrier,
        public readonly ?string $cutoffAt,       // RFC3339 — anchor for the countdown ticking
        public readonly ?string $cutoffTime,     // "11:00" — for display
        public readonly bool $wasCutoffMissed,
    ) {
    }

    /**
     * Builds from the xl0g JSON response. Returns null if there is no usable date
     * (the caller then hides the widget — never a broken widget).
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
