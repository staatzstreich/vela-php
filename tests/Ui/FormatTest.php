<?php

declare(strict_types=1);

namespace Vela\Tests\Ui;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Ui\Format;

/**
 * Format's five static helpers are all pure functions with no filesystem/
 * network dependency — the last untested class flagged during the App.php
 * test-coverage push. detectLocalTimezone() is the one exception: it reads
 * the real /etc/localtime symlink, environment-dependent state this project
 * has no business manipulating in a test — covered by a single "doesn't
 * crash and returns something non-empty" smoke check instead of asserting a
 * specific value.
 */
final class FormatTest extends TestCase
{
    #[Test]
    public function sizeFormatsZeroBytesInTheByteUnit(): void
    {
        self::assertSame('      0 B', Format::size(0));
    }

    #[Test]
    public function sizeStaysInBytesBelowOneKilobyte(): void
    {
        self::assertSame('    500 B', Format::size(500));
    }

    #[Test]
    public function sizeSwitchesToKilobytesAtExactlyOneThousandTwentyFour(): void
    {
        self::assertSame('   1.0 KB', Format::size(1024));
    }

    #[Test]
    public function sizeShowsOneDecimalPlaceForFractionalUnits(): void
    {
        self::assertSame('   1.5 KB', Format::size(1536));
    }

    #[Test]
    public function sizeStepsThroughUnitsUpToMegabytes(): void
    {
        self::assertSame('   1.0 MB', Format::size(1024 * 1024));
    }

    #[Test]
    public function sizeStepsUpToTerabytes(): void
    {
        self::assertSame('   1.0 TB', Format::size(1024 ** 4));
    }

    #[Test]
    public function sizeClampsAtTerabytesRatherThanInventingALargerUnit(): void
    {
        // 1024 TB — the unit list has nothing past TB, so the loop must stop
        // advancing units and just let the number grow instead.
        self::assertSame('1024.0 TB', Format::size(1024 ** 4 * 1024));
    }

    #[Test]
    public function dateFormatsATimestampAsYearMonthDayHourMinuteInTheConfiguredTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            self::assertSame('1970-01-01 00:00', Format::date(0));
            self::assertSame('2024-01-15 13:30', Format::date(1705325400));
            self::assertSame('2001-09-09 01:46', Format::date(1000000000));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[Test]
    public function truncateNameReturnsEmptyStringForANonPositiveMaxLength(): void
    {
        self::assertSame('', Format::truncateName('anything', 0));
        self::assertSame('', Format::truncateName('anything', -5));
    }

    #[Test]
    public function truncateNameLeavesShortNamesUnchanged(): void
    {
        self::assertSame('short.txt', Format::truncateName('short.txt', 20));
    }

    #[Test]
    public function truncateNameLeavesANameExactlyAtTheLimitUnchanged(): void
    {
        self::assertSame('exactly10c', Format::truncateName('exactly10c', 10));
    }

    #[Test]
    public function truncateNameCutsLongNamesAndAppendsAnEllipsis(): void
    {
        self::assertSame('this-is-...', Format::truncateName('this-is-a-very-long-filename.txt', 11));
    }

    #[Test]
    public function truncateNameWithAMaxLengthBelowThreeStillAppendsTheFullEllipsis(): void
    {
        // cut = max(0, maxLen - 3) floors at 0, so the result is just "..."
        // — three characters, even though maxLen asked for fewer.
        self::assertSame('...', Format::truncateName('a-long-name.txt', 2));
    }

    #[Test]
    public function truncateNameCountsMultibyteCharactersNotBytes(): void
    {
        $name = 'ä-ö-ü-really-long-name-with-umlauts.txt';
        $result = Format::truncateName($name, 10);

        self::assertSame(10, mb_strlen($result));
        self::assertStringEndsWith('...', $result);
    }

    #[Test]
    public function padRightPadsShortTextWithTrailingSpacesToTheExactWidth(): void
    {
        self::assertSame('ab   ', Format::padRight('ab', 5));
    }

    #[Test]
    public function padRightLeavesTextExactlyAtTheWidthUnchanged(): void
    {
        self::assertSame('abcde', Format::padRight('abcde', 5));
    }

    #[Test]
    public function padRightLeavesTextLongerThanTheWidthUnchanged(): void
    {
        self::assertSame('abcdefgh', Format::padRight('abcdefgh', 5));
    }

    #[Test]
    public function padRightCountsMultibyteCharactersNotBytesForThePadAmount(): void
    {
        // "äöü" is 3 characters but 6 bytes in UTF-8 — padding must be based
        // on the character count, or this would come out two spaces short.
        self::assertSame('äöü  ', Format::padRight('äöü', 5));
    }

    #[Test]
    public function detectLocalTimezoneReturnsANonEmptyString(): void
    {
        self::assertNotSame('', Format::detectLocalTimezone());
    }
}
