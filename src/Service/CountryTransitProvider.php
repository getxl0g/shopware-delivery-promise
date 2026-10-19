<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the configured additional transit time per delivery country (sales-channel scoped).
 *
 * The xl0g API knows no destination country — it returns the date for the base country (DE). For other
 * delivery countries the shop operator maintains a mapping in the plugin config (one line per country,
 * e.g. `AT=1`) whose days are added to the API date.
 *
 * Countries not configured ⇒ 0 days, i.e. the API value as for DE (on purpose: the widget is hidden only
 * on real API problems, never because of a missing country configuration).
 * Invalid lines are logged and ignored — never break the storefront.
 */
class CountryTransitProvider
{
    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function additionalDays(?string $countryIso, ?string $salesChannelId = null): int
    {
        if ($countryIso === null || trim($countryIso) === '') {
            return 0;
        }

        return $this->mapping($salesChannelId)[strtoupper(trim($countryIso))] ?? 0;
    }

    public function skipWeekends(?string $salesChannelId = null): bool
    {
        $v = $this->systemConfig->get('Xl0gDeliveryPromise.config.internationalSkipWeekends', $salesChannelId);

        return $v === null ? true : (bool) $v; // default on
    }

    /**
     * Parses the textarea mapping — one line per country: `AT=1`. Empty lines and `#` comments are
     * allowed; anything else unreadable is logged as a warning (throttled, non-critical).
     *
     * @return array<string, int> ISO code (upper case) => additional days
     */
    private function mapping(?string $salesChannelId): array
    {
        $raw = (string) $this->systemConfig->get('Xl0gDeliveryPromise.config.internationalTransitDays', $salesChannelId);
        if (trim($raw) === '') {
            return [];
        }

        $mapping = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^([a-zA-Z]{2})\s*=\s*(\d{1,3})$/', $line, $m)) {
                $this->logger->warning('xl0g delivery promise: ignoring invalid country transit line.', [
                    'line' => $line,
                ]);

                continue;
            }

            $mapping[strtoupper($m[1])] = (int) $m[2];
        }

        return $mapping;
    }
}
