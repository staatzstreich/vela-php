<?php

declare(strict_types=1);

namespace Vela\Dialog;

/**
 * Quick image preview overlay (v key) — no Rust original, this feature never
 * existed in vela's design. $previewPath is either the original local path
 * (nothing to clean up) or a scratch copy (downloaded from SFTP and/or
 * resized by Imagick) — whenever $tempDir is non-null, App::closeImagePreview()
 * must remove $previewPath and any other file inside $tempDir, then rmdir it.
 */
final class ImagePreviewDialog
{
    public function __construct(
        public readonly string $entryName,
        public readonly string $previewPath,
        public readonly ?string $tempDir,
    ) {
    }
}
