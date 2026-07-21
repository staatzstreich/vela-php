<?php

declare(strict_types=1);

namespace Vela\Theme;

/**
 * Mirrors vela's src/ui/theme.rs ThemeChoice enum (Auto | Dark | Light |
 * Custom(String)). PHP enum cases can't carry per-instance data, so this is
 * a plain value object instead, with the "kind" tag as a backed-enum-like
 * string and $customName only set when kind === 'Custom'.
 */
final class ThemeChoice
{
    private function __construct(
        public readonly string $kind,
        public readonly ?string $customName = null,
    ) {
    }

    public static function auto(): self
    {
        return new self('Auto');
    }

    public static function dark(): self
    {
        return new self('Dark');
    }

    public static function light(): self
    {
        return new self('Light');
    }

    public static function custom(string $name): self
    {
        return new self('Custom', $name);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->customName === $other->customName;
    }

    public function resolve(): Theme
    {
        return match ($this->kind) {
            'Dark' => Theme::dark(),
            'Light' => Theme::light(),
            'Custom' => ThemeStore::loadCustomTheme((string) $this->customName) ?? Theme::dark(),
            default => self::resolveAuto(),
        };
    }

    private static function resolveAuto(): Theme
    {
        $val = getenv('COLORFGBG');
        if ($val === false) {
            return Theme::dark();
        }
        $parts = preg_split('/[:;]/', $val);
        $bgVal = $parts !== false ? (int) end($parts) : null;

        return $bgVal !== null && $bgVal < 8 ? Theme::dark() : Theme::light();
    }

    public function label(): string
    {
        return $this->kind === 'Custom' ? (string) $this->customName : $this->kind;
    }

    public function serName(): string
    {
        return $this->label();
    }

    public static function fromString(string $s): self
    {
        return match (strtolower($s)) {
            'auto' => self::auto(),
            'dark' => self::dark(),
            'light' => self::light(),
            default => self::custom($s),
        };
    }
}
