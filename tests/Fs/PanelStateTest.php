<?php

declare(strict_types=1);

namespace Vela\Tests\Fs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Fs\FileEntry;
use Vela\Fs\PanelState;

/** Covers PanelState::findMatch() — vim-style "/" jump-to-match search. */
final class PanelStateTest extends TestCase
{
    #[Test]
    public function emptyQueryMatchesNothing(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt');

        self::assertNull($panel->findMatch('', 0, true));
    }

    #[Test]
    public function emptyPanelMatchesNothing(): void
    {
        $panel = new PanelState('/does-not-matter');

        self::assertNull($panel->findMatch('a', 0, true));
    }

    #[Test]
    public function forwardSearchFindsTheNearestMatchAtOrAfterFrom(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt', 'gamma.txt', 'delta.txt');

        self::assertSame(2, $panel->findMatch('gamma', 0, true));
    }

    #[Test]
    public function forwardSearchIsCaseInsensitive(): void
    {
        $panel = self::panelWith('alpha.txt', 'BETA.TXT');

        self::assertSame(1, $panel->findMatch('beta', 0, true));
    }

    #[Test]
    public function forwardSearchWrapsAroundTheEndOfTheList(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt', 'gamma.txt');

        self::assertSame(0, $panel->findMatch('alpha', 1, true));
    }

    #[Test]
    public function backwardSearchFindsTheNearestMatchAtOrBeforeFrom(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt', 'gamma.txt', 'delta.txt');

        self::assertSame(1, $panel->findMatch('beta', 3, false));
    }

    #[Test]
    public function backwardSearchWrapsAroundTheStartOfTheList(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt', 'gamma.txt');

        self::assertSame(2, $panel->findMatch('gamma', 0, false));
    }

    #[Test]
    public function fromItselfMatchesWhenItSatisfiesTheQuery(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt', 'gamma.txt');

        self::assertSame(1, $panel->findMatch('beta', 1, true));
        self::assertSame(1, $panel->findMatch('beta', 1, false));
    }

    #[Test]
    public function noMatchReturnsNull(): void
    {
        $panel = self::panelWith('alpha.txt', 'beta.txt');

        self::assertNull($panel->findMatch('zzz', 0, true));
    }

    private static function panelWith(string ...$names): PanelState
    {
        $panel = new PanelState('/does-not-matter');
        foreach ($names as $name) {
            $panel->entries[] = new FileEntry($name, 0, null, false);
        }

        return $panel;
    }
}
