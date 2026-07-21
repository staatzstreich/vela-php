<?php

declare(strict_types=1);

namespace Vela\Theme;

use Internal\Toml\Toml;

/** Mirrors the persistence helpers at the bottom of vela's src/ui/theme.rs. */
final class ThemeStore
{
    public static function configDir(): string
    {
        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: '/tmp');

        return rtrim($home, '/') . '/.config/vela';
    }

    public static function settingsPath(): string
    {
        return self::configDir() . '/settings.toml';
    }

    public static function themesDir(): string
    {
        return self::configDir() . '/themes';
    }

    public static function loadThemeChoice(): ThemeChoice
    {
        $path = self::settingsPath();
        if (!is_file($path)) {
            return ThemeChoice::auto();
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return ThemeChoice::auto();
        }
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'theme = ')) {
                $value = trim(substr($line, strlen('theme = ')));
                $value = trim($value, "\"'");

                return ThemeChoice::fromString($value);
            }
        }

        return ThemeChoice::auto();
    }

    public static function saveThemeChoice(ThemeChoice $choice): void
    {
        $path = self::settingsPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($path, "theme = \"{$choice->serName()}\"\n");
    }

    /** @return string[] */
    public static function customThemeNames(): array
    {
        $dir = self::themesDir();
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, '.toml')) {
                continue;
            }
            $stem = substr($entry, 0, -strlen('.toml'));
            $lower = strtolower($stem);
            if ($lower !== 'dark' && $lower !== 'light') {
                $names[] = $stem;
            }
        }
        sort($names);

        return $names;
    }

    public static function loadCustomTheme(string $name): ?Theme
    {
        $path = self::themesDir() . "/{$name}.toml";
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        try {
            $data = Toml::parseToArray($content);
        } catch (\Throwable) {
            return null;
        }

        return Theme::fromArray($data);
    }

    /** Ensure the theme template files exist in ~/.config/vela/themes/. Does not overwrite existing files. */
    public static function ensureThemes(): void
    {
        $dir = self::themesDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        foreach ([
            'dark' => Theme::dark(),
            'light' => Theme::light(),
            'custom' => Theme::customTemplate(),
        ] as $name => $theme) {
            $path = "{$dir}/{$name}.toml";
            if (is_file($path)) {
                continue;
            }
            $data = array_merge(['name' => $name], $theme->toArray());
            @file_put_contents($path, (string) Toml::encode($data));
        }
    }
}
