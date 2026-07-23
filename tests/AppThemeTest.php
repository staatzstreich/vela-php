<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyModifiers;
use PHPUnit\Framework\Attributes\Test;
use Vela\Theme\ThemeChoice;
use Vela\Theme\ThemeStore;

/**
 * Covers Ctrl+T theme cycling (App::cycleTheme()/nextTheme()). Every fresh
 * App::__construct() calls ThemeStore::ensureThemes(), which always creates
 * dark.toml/light.toml/custom.toml under the scratch HOME if missing —
 * customThemeNames() only excludes "dark"/"light" by name, not "custom", so
 * cycling in a fresh scratch HOME always has exactly one custom stop:
 * Auto -> Dark -> Light -> Custom("custom") -> back to Auto (confirmed by
 * reading ThemeStore::ensureThemes()/customThemeNames() directly — a fourth
 * stop worth asserting explicitly rather than assuming a 3-stop cycle).
 */
final class AppThemeTest extends AppTestCase
{
    #[Test]
    public function freshAppStartsOnAutoTheme(): void
    {
        $app = $this->makeApp();

        self::assertTrue($app->themeChoice->equals(ThemeChoice::auto()));
    }

    #[Test]
    public function ctrlTCyclesAutoToDark(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));

        self::assertTrue($app->themeChoice->equals(ThemeChoice::dark()));
    }

    #[Test]
    public function ctrlTCyclesDarkToLight(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));
        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));

        self::assertTrue($app->themeChoice->equals(ThemeChoice::light()));
    }

    #[Test]
    public function ctrlTCyclesLightToTheCustomTemplate(): void
    {
        $app = $this->makeApp();

        for ($i = 0; $i < 3; $i++) {
            $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));
        }

        self::assertTrue($app->themeChoice->equals(ThemeChoice::custom('custom')));
    }

    #[Test]
    public function ctrlTCyclesCustomBackToAuto(): void
    {
        $app = $this->makeApp();

        for ($i = 0; $i < 4; $i++) {
            $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));
        }

        self::assertTrue($app->themeChoice->equals(ThemeChoice::auto()));
    }

    #[Test]
    public function themeChoicePersistsToSettingsTomlUnderScratchHome(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));

        self::assertTrue(ThemeStore::loadThemeChoice()->equals(ThemeChoice::dark()));
    }

    #[Test]
    public function statusMessageReportsTheNewThemesLabelAfterEachCycle(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));
        self::assertSame('Theme: Dark', $app->statusMessage);

        for ($i = 0; $i < 2; $i++) {
            $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));
        }
        self::assertSame('Theme: custom', $app->statusMessage);
    }
}
