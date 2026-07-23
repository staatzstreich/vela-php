<?php

declare(strict_types=1);

namespace Vela\Tests;

use PHPUnit\Framework\TestCase;
use Vela\App;
use Vela\Fs\FileEntry;

/**
 * Shared fixture for every App test class. App::__construct() unconditionally
 * touches $_SERVER['HOME']-based paths (ThemeStore::ensureThemes()/
 * loadThemeChoice(), ProfileStore::load()), so every test needs HOME isolated
 * to a scratch directory — same pattern as ProfileStoreTest, just also
 * providing the two scratch directories App's constructor requires for its
 * left/right panels (PanelState::loadLocal() throws if scandir() fails).
 *
 * This is the one deliberate break from this project's "one self-contained
 * test class per file" convention: every App*Test file needs this identical
 * three-scratch-root setup, and copy-pasting it per file would mean any bugfix
 * needs synchronized edits everywhere instead of one.
 */
abstract class AppTestCase extends TestCase
{
    protected string $scratchHome;

    protected string $scratchLeft;

    protected string $scratchRight;

    private ?string $originalHome;

    protected function setUp(): void
    {
        $this->scratchHome = self::freshScratchDir();
        $this->scratchLeft = self::freshScratchDir();
        $this->scratchRight = self::freshScratchDir();

        $home = $_SERVER['HOME'] ?? null;
        $this->originalHome = is_string($home) ? $home : null;
        $_SERVER['HOME'] = $this->scratchHome;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== null) {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }
        foreach ([$this->scratchHome, $this->scratchLeft, $this->scratchRight] as $dir) {
            self::removeDirectoryRecursively($dir);
        }
    }

    protected function makeApp(): App
    {
        return new App($this->scratchLeft, $this->scratchRight);
    }

    /** @param list<FileEntry> $entries */
    protected static function indexOf(array $entries, string $name): ?int
    {
        foreach ($entries as $i => $entry) {
            if ($entry->name === $name) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<FileEntry> $entries */
    protected static function mustIndexOf(array $entries, string $name): int
    {
        $index = self::indexOf($entries, $name);
        self::assertNotNull($index);

        return $index;
    }

    private static function freshScratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/vela-php-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private static function removeDirectoryRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::removeDirectoryRecursively($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
