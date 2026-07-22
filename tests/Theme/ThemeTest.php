<?php

declare(strict_types=1);

namespace Vela\Tests\Theme;

use PhpTui\Tui\Color\AnsiColor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Theme\Theme;

/**
 * Converts the ad-hoc verification from milestone 7's scratch script into a
 * checked-in test. Covers the TOML round-trip that matters most for
 * Rust/PHP theme-file interop: Theme::toArray() must produce the same
 * snake_case keys Theme::fromArray() (and the real Rust-written theme
 * files) expect.
 */
final class ThemeTest extends TestCase
{
    #[Test]
    public function toArrayProducesAllFiftyColorFields(): void
    {
        $fields = Theme::dark()->toArray();

        self::assertCount(50, $fields);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeysForRustInterop(): void
    {
        $fields = Theme::dark()->toArray();

        self::assertArrayHasKey('panel_active_border', $fields);
        self::assertArrayHasKey('shell_label', $fields);
        self::assertArrayNotHasKey('panelActiveBorder', $fields);
    }

    #[Test]
    public function fromArrayRoundTripsWithToArray(): void
    {
        $dark = Theme::dark();

        $roundTripped = Theme::fromArray($dark->toArray());

        self::assertNotNull($roundTripped);
        self::assertSame($dark->panelActiveBorder, $roundTripped->panelActiveBorder);
        self::assertSame($dark->shellLabel, $roundTripped->shellLabel);
    }

    #[Test]
    public function fromArrayReturnsNullWhenAFieldIsMissing(): void
    {
        $incomplete = Theme::dark()->toArray();
        unset($incomplete['text_danger']);

        self::assertNull(Theme::fromArray($incomplete));
    }

    #[Test]
    public function fromArrayReturnsNullOnAnUnknownColorName(): void
    {
        $data = Theme::dark()->toArray();
        $data['text_danger'] = 'NotARealColor';

        self::assertNull(Theme::fromArray($data));
    }

    #[Test]
    public function parseColorAndColorNameAreInverses(): void
    {
        self::assertSame(AnsiColor::Cyan, Theme::parseColor('Cyan'));
        self::assertSame('Cyan', Theme::colorName(AnsiColor::Cyan));
    }

    #[Test]
    public function darkAndLightProduceDifferentPanelBorderColors(): void
    {
        self::assertNotSame(Theme::dark()->panelActiveBorder, Theme::light()->panelActiveBorder);
    }
}
