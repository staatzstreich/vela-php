<?php

declare(strict_types=1);

namespace Vela\Theme;

use PhpTui\Tui\Color\AnsiColor;

/** Mirrors vela's src/ui/theme.rs Theme struct. */
final class Theme
{
    public const HIGHLIGHT_SYMBOL = '► ';

    /** Field names in constructor-declaration order — used for TOML round-tripping. */
    private const FIELDS = [
        'panelActiveBorder', 'panelInactiveBorder', 'directoryIcon', 'fileName', 'markedEntry',
        'markIndicator', 'sizeText', 'dateText', 'permissionText', 'highlightBg', 'highlightFg',
        'hintBadgeBg', 'hintBadgeFg', 'hintBadgeDangerBg', 'hintLabel', 'statusMessage', 'hintBarBg',
        'transferFilledFg', 'transferEmptyBg', 'uploadBar', 'downloadBar', 'transferRowBg', 'filenameText',
        'dialogActiveBorder', 'dialogInactiveBorder', 'dialogWarningBorder', 'dialogErrorBorder', 'dialogSuccessBorder',
        'textPrimary', 'textSecondary', 'textMuted', 'textActive', 'textInactive', 'cursorBg', 'cursorFg',
        'toggleOn', 'toggleOff', 'textDanger', 'textSuccess', 'textWarning', 'textInfo', 'profileActive',
        'badgeBg', 'badgeFg', 'shellCursorBg', 'shellCursorFg', 'shellOutputBg', 'shellLabel',
        'highlightPrimaryBg', 'highlightPrimaryFg',
    ];

    public function __construct(
        // Panels
        public AnsiColor $panelActiveBorder,
        public AnsiColor $panelInactiveBorder,
        public AnsiColor $directoryIcon,
        public AnsiColor $fileName,
        public AnsiColor $markedEntry,
        public AnsiColor $markIndicator,
        public AnsiColor $sizeText,
        public AnsiColor $dateText,
        public AnsiColor $permissionText,
        public AnsiColor $highlightBg,
        public AnsiColor $highlightFg,

        // Status bar
        public AnsiColor $hintBadgeBg,
        public AnsiColor $hintBadgeFg,
        public AnsiColor $hintBadgeDangerBg,
        public AnsiColor $hintLabel,
        public AnsiColor $statusMessage,
        public AnsiColor $hintBarBg,
        public AnsiColor $transferFilledFg,
        public AnsiColor $transferEmptyBg,
        public AnsiColor $uploadBar,
        public AnsiColor $downloadBar,
        public AnsiColor $transferRowBg,
        public AnsiColor $filenameText,

        // Dialogs - borders
        public AnsiColor $dialogActiveBorder,
        public AnsiColor $dialogInactiveBorder,
        public AnsiColor $dialogWarningBorder,
        public AnsiColor $dialogErrorBorder,
        public AnsiColor $dialogSuccessBorder,

        // Dialogs - text
        public AnsiColor $textPrimary,
        public AnsiColor $textSecondary,
        public AnsiColor $textMuted,
        public AnsiColor $textActive,
        public AnsiColor $textInactive,
        public AnsiColor $cursorBg,
        public AnsiColor $cursorFg,
        public AnsiColor $toggleOn,
        public AnsiColor $toggleOff,
        public AnsiColor $textDanger,
        public AnsiColor $textSuccess,
        public AnsiColor $textWarning,
        public AnsiColor $textInfo,
        public AnsiColor $profileActive,
        public AnsiColor $badgeBg,
        public AnsiColor $badgeFg,

        // Shell
        public AnsiColor $shellCursorBg,
        public AnsiColor $shellCursorFg,
        public AnsiColor $shellOutputBg,
        public AnsiColor $shellLabel,
        public AnsiColor $highlightPrimaryBg,
        public AnsiColor $highlightPrimaryFg,
    ) {
    }

    public static function dark(): self
    {
        return new self(
            panelActiveBorder: AnsiColor::Cyan,
            panelInactiveBorder: AnsiColor::DarkGray,
            directoryIcon: AnsiColor::Yellow,
            fileName: AnsiColor::White,
            markedEntry: AnsiColor::Yellow,
            markIndicator: AnsiColor::Yellow,
            sizeText: AnsiColor::Gray,
            dateText: AnsiColor::DarkGray,
            permissionText: AnsiColor::DarkGray,
            highlightBg: AnsiColor::Blue,
            highlightFg: AnsiColor::White,

            hintBadgeBg: AnsiColor::DarkGray,
            hintBadgeFg: AnsiColor::White,
            hintBadgeDangerBg: AnsiColor::Red,
            hintLabel: AnsiColor::White,
            statusMessage: AnsiColor::Yellow,
            hintBarBg: AnsiColor::Black,
            transferFilledFg: AnsiColor::Black,
            transferEmptyBg: AnsiColor::DarkGray,
            uploadBar: AnsiColor::Green,
            downloadBar: AnsiColor::Cyan,
            transferRowBg: AnsiColor::Black,
            filenameText: AnsiColor::White,

            dialogActiveBorder: AnsiColor::Cyan,
            dialogInactiveBorder: AnsiColor::DarkGray,
            dialogWarningBorder: AnsiColor::Yellow,
            dialogErrorBorder: AnsiColor::Red,
            dialogSuccessBorder: AnsiColor::Green,

            textPrimary: AnsiColor::White,
            textSecondary: AnsiColor::Gray,
            textMuted: AnsiColor::DarkGray,
            textActive: AnsiColor::White,
            textInactive: AnsiColor::Gray,
            cursorBg: AnsiColor::Cyan,
            cursorFg: AnsiColor::Black,
            toggleOn: AnsiColor::Green,
            toggleOff: AnsiColor::DarkGray,
            textDanger: AnsiColor::Red,
            textSuccess: AnsiColor::Green,
            textWarning: AnsiColor::Yellow,
            textInfo: AnsiColor::Blue,
            profileActive: AnsiColor::Green,
            badgeBg: AnsiColor::DarkGray,
            badgeFg: AnsiColor::White,

            shellCursorBg: AnsiColor::White,
            shellCursorFg: AnsiColor::Black,
            shellOutputBg: AnsiColor::Black,
            shellLabel: AnsiColor::Yellow,
            highlightPrimaryBg: AnsiColor::Blue,
            highlightPrimaryFg: AnsiColor::White,
        );
    }

    public static function light(): self
    {
        return new self(
            panelActiveBorder: AnsiColor::Blue,
            panelInactiveBorder: AnsiColor::Gray,
            directoryIcon: AnsiColor::Blue,
            fileName: AnsiColor::Black,
            markedEntry: AnsiColor::Blue,
            markIndicator: AnsiColor::Blue,
            sizeText: AnsiColor::DarkGray,
            dateText: AnsiColor::Gray,
            permissionText: AnsiColor::Gray,
            highlightBg: AnsiColor::Cyan,
            highlightFg: AnsiColor::Black,

            hintBadgeBg: AnsiColor::Gray,
            hintBadgeFg: AnsiColor::White,
            hintBadgeDangerBg: AnsiColor::Red,
            hintLabel: AnsiColor::Black,
            statusMessage: AnsiColor::Blue,
            hintBarBg: AnsiColor::White,
            transferFilledFg: AnsiColor::White,
            transferEmptyBg: AnsiColor::Gray,
            uploadBar: AnsiColor::Green,
            downloadBar: AnsiColor::Blue,
            transferRowBg: AnsiColor::White,
            filenameText: AnsiColor::Black,

            dialogActiveBorder: AnsiColor::Blue,
            dialogInactiveBorder: AnsiColor::Gray,
            dialogWarningBorder: AnsiColor::Blue,
            dialogErrorBorder: AnsiColor::Red,
            dialogSuccessBorder: AnsiColor::Green,

            textPrimary: AnsiColor::Black,
            textSecondary: AnsiColor::DarkGray,
            textMuted: AnsiColor::Gray,
            textActive: AnsiColor::Black,
            textInactive: AnsiColor::DarkGray,
            cursorBg: AnsiColor::Blue,
            cursorFg: AnsiColor::White,
            toggleOn: AnsiColor::Green,
            toggleOff: AnsiColor::Gray,
            textDanger: AnsiColor::Red,
            textSuccess: AnsiColor::Green,
            textWarning: AnsiColor::Blue,
            textInfo: AnsiColor::Blue,
            profileActive: AnsiColor::Green,
            badgeBg: AnsiColor::Gray,
            badgeFg: AnsiColor::White,

            shellCursorBg: AnsiColor::Black,
            shellCursorFg: AnsiColor::White,
            shellOutputBg: AnsiColor::White,
            shellLabel: AnsiColor::Blue,
            highlightPrimaryBg: AnsiColor::Cyan,
            highlightPrimaryFg: AnsiColor::Black,
        );
    }

    /** Starting point for a new custom theme file. */
    public static function customTemplate(): self
    {
        return self::dark();
    }

    /**
     * TOML keys use snake_case (panel_active_border) — the same names the
     * Rust version's serde derive writes — so theme files are shared
     * between both implementations, like profiles.toml already is.
     *
     * @return array<string,string> snake_case field name => color name
     */
    public function toArray(): array
    {
        $out = [];
        foreach (get_object_vars($this) as $field => $color) {
            $out[self::toSnakeCase($field)] = self::colorName($color);
        }

        return $out;
    }

    /** @param array<string,mixed> $data snake_case keys, as written by either implementation */
    public static function fromArray(array $data): ?self
    {
        $colors = [];
        foreach (self::FIELDS as $field) {
            $key = self::toSnakeCase($field);
            $color = isset($data[$key]) ? self::parseColor((string) $data[$key]) : null;
            if ($color === null) {
                return null;
            }
            $colors[$field] = $color;
        }

        return new self(...$colors);
    }

    private static function toSnakeCase(string $field): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', $field));
    }

    public static function parseColor(string $name): ?AnsiColor
    {
        return match ($name) {
            'Black' => AnsiColor::Black,
            'Red' => AnsiColor::Red,
            'Green' => AnsiColor::Green,
            'Yellow' => AnsiColor::Yellow,
            'Blue' => AnsiColor::Blue,
            'Magenta' => AnsiColor::Magenta,
            'Cyan' => AnsiColor::Cyan,
            'White' => AnsiColor::White,
            'Gray' => AnsiColor::Gray,
            'DarkGray' => AnsiColor::DarkGray,
            'LightRed' => AnsiColor::LightRed,
            'LightGreen' => AnsiColor::LightGreen,
            'LightYellow' => AnsiColor::LightYellow,
            'LightBlue' => AnsiColor::LightBlue,
            'LightMagenta' => AnsiColor::LightMagenta,
            'LightCyan' => AnsiColor::LightCyan,
            'LightWhite', 'LightGray' => AnsiColor::White,
            default => null,
        };
    }

    public static function colorName(AnsiColor $color): string
    {
        return match ($color) {
            AnsiColor::Black => 'Black',
            AnsiColor::Red => 'Red',
            AnsiColor::Green => 'Green',
            AnsiColor::Yellow => 'Yellow',
            AnsiColor::Blue => 'Blue',
            AnsiColor::Magenta => 'Magenta',
            AnsiColor::Cyan => 'Cyan',
            AnsiColor::White => 'White',
            AnsiColor::Gray => 'Gray',
            AnsiColor::DarkGray => 'DarkGray',
            AnsiColor::LightRed => 'LightRed',
            AnsiColor::LightGreen => 'LightGreen',
            AnsiColor::LightYellow => 'LightYellow',
            AnsiColor::LightBlue => 'LightBlue',
            AnsiColor::LightMagenta => 'LightMagenta',
            AnsiColor::LightCyan => 'LightCyan',
            default => 'White',
        };
    }
}
