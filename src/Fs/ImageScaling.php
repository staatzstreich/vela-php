<?php

declare(strict_types=1);

namespace Vela\Fs;

/**
 * Pure arithmetic for the image-preview resize step (App::openImagePreview()).
 * Deliberately has zero dependency on ext-imagick so it can be unit tested
 * without it — everything else in that feature needs the real extension.
 */
final class ImageScaling
{
    /**
     * Aspect-ratio-preserving "best fit" within $maxWidth x $maxHeight.
     * Never upscales: an image already within bounds is returned unchanged.
     *
     * @return array{width:int,height:int}
     */
    public static function fit(int $srcWidth, int $srcHeight, int $maxWidth, int $maxHeight): array
    {
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            return ['width' => max(1, $srcWidth), 'height' => max(1, $srcHeight)];
        }

        if ($srcWidth <= $maxWidth && $srcHeight <= $maxHeight) {
            return ['width' => $srcWidth, 'height' => $srcHeight];
        }

        $scale = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);

        return [
            'width' => max(1, (int) round($srcWidth * $scale)),
            'height' => max(1, (int) round($srcHeight * $scale)),
        ];
    }
}
