<?php

declare(strict_types=1);

namespace Vela\Fs;

/**
 * A single entry in a file panel (local for now — mirrors vela's
 * src/app.rs FileEntry, minus the `permissions` field which only applies
 * to remote/SFTP entries and isn't needed until that milestone).
 */
final class FileEntry
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $size,
        public readonly ?int $modifiedAt,
        public readonly bool $isDir,
    ) {
    }
}
