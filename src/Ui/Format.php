<?php

declare(strict_types=1);

namespace Vela\Ui;

/** Mirrors the formatting helpers in vela's src/ui/panels.rs. */
final class Format
{
    public const COL_SIZE = 9;
    public const COL_DATE = 16;

    public static function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unitIdx = 0;
        while ($value >= 1024.0 && $unitIdx + 1 < count($units)) {
            $value /= 1024.0;
            $unitIdx++;
        }

        if ($unitIdx === 0) {
            return sprintf('%7d B', $bytes);
        }

        return sprintf('%6.1f %s', $value, $units[$unitIdx]);
    }

    /** Local time, formatted as "YYYY-MM-DD HH:MM". */
    public static function date(int $timestamp): string
    {
        return date('Y-m-d H:i', $timestamp);
    }

    public static function truncateName(string $name, int $maxLen): string
    {
        if ($maxLen <= 0) {
            return '';
        }

        $len = mb_strlen($name);
        if ($len <= $maxLen) {
            return $name;
        }

        $cut = max(0, $maxLen - 3);

        return mb_substr($name, 0, $cut) . '...';
    }

    /** Multibyte-safe right-padding (str_pad operates on bytes, not chars). */
    public static function padRight(string $text, int $width): string
    {
        $pad = $width - mb_strlen($text);

        return $pad > 0 ? $text . str_repeat(' ', $pad) : $text;
    }

    /**
     * Detect the system's local timezone so date() output matches what a
     * user sees in their own shell, mirroring vela's tzset()-based approach.
     */
    public static function detectLocalTimezone(): string
    {
        $link = @readlink('/etc/localtime');
        if ($link !== false) {
            $pos = strpos($link, 'zoneinfo/');
            if ($pos !== false) {
                return substr($link, $pos + strlen('zoneinfo/'));
            }
        }

        return date_default_timezone_get() ?: 'UTC';
    }
}
