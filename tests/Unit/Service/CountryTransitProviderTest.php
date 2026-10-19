<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Xl0g\DeliveryPromise\Service\CountryTransitProvider;

/**
 * @covers \Xl0g\DeliveryPromise\Service\CountryTransitProvider
 */
class CountryTransitProviderTest extends TestCase
{
    public function testParsesMappingAndReturnsDays(): void
    {
        $p = $this->provider("AT=1\nCH=4\nFR=2");

        self::assertSame(1, $p->additionalDays('AT'));
        self::assertSame(4, $p->additionalDays('CH'));
        self::assertSame(2, $p->additionalDays('FR'));
    }

    public function testLookupAndMappingAreCaseInsensitive(): void
    {
        $p = $this->provider('at=1');

        self::assertSame(1, $p->additionalDays('AT'));
        self::assertSame(1, $p->additionalDays('at'));
    }

    public function testToleratesWhitespaceCommentsAndBlankLines(): void
    {
        $p = $this->provider("# neighbouring countries\n\n  AT = 1  \r\nCH=4\n");

        self::assertSame(1, $p->additionalDays('AT'));
        self::assertSame(4, $p->additionalDays('CH'));
    }

    public function testIgnoresInvalidLines(): void
    {
        $p = $this->provider("Austria=1\nAT=many\nAT=1=2\nCH=4");

        self::assertSame(0, $p->additionalDays('AT'));
        self::assertSame(4, $p->additionalDays('CH'));
    }

    public function testUnknownCountryAndEmptyConfigReturnZero(): void
    {
        self::assertSame(0, $this->provider('AT=1')->additionalDays('NL'));
        self::assertSame(0, $this->provider('')->additionalDays('AT'));
        self::assertSame(0, $this->provider('AT=1')->additionalDays(null));
        self::assertSame(0, $this->provider('AT=1')->additionalDays('  '));
    }

    public function testSkipWeekendsDefaultsToTrue(): void
    {
        self::assertTrue($this->provider('', skipWeekends: null)->skipWeekends());
        self::assertFalse($this->provider('', skipWeekends: false)->skipWeekends());
        self::assertTrue($this->provider('', skipWeekends: true)->skipWeekends());
    }

    private function provider(string $transitDays, ?bool $skipWeekends = null): CountryTransitProvider
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['Xl0gDeliveryPromise.config.internationalTransitDays', null, $transitDays],
            ['Xl0gDeliveryPromise.config.internationalSkipWeekends', null, $skipWeekends],
        ]);

        return new CountryTransitProvider($systemConfig, new NullLogger());
    }
}
