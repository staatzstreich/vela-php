<?php

declare(strict_types=1);

namespace Vela\Fs;

/**
 * A single entry in a file panel (local or remote). Mirrors vela's
 * src/app.rs FileEntry. `permissions` (a "rwxr-xr-x" string) is only set
 * for remote/SFTP entries.
 */
final class FileEntry
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $size,
        public readonly ?int $modifiedAt,
        public readonly bool $isDir,
        public readonly ?string $permissions = null,
    ) {
    }
}
