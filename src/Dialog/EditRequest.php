<?php

declare(strict_types=1);

namespace Vela\Dialog;

/**
 * Mirrors vela's src/app.rs EditRequest (Local | Remote variants). Prepared
 * by App::prepareEdit() (F4), consumed by bin/vela.php's main loop, which
 * suspends the TUI, runs $EDITOR, then calls App::finishEdit().
 */
final class EditRequest
{
    private function __construct(
        /** The file the editor should open (local path, or the temp download for remote). */
        public readonly string $editPath,
        public readonly ?string $remotePath,
        public readonly ?int $mtimeBefore,
        /** Temp dir to delete after the edit (remote case only — Rust's TempDir RAII, done by hand here). */
        public readonly ?string $tempDir,
    ) {
    }

    public static function local(string $path): self
    {
        return new self($path, null, null, null);
    }

    public static function remote(string $tempPath, string $remotePath, int $mtimeBefore, string $tempDir): self
    {
        return new self($tempPath, $remotePath, $mtimeBefore, $tempDir);
    }

    public function isRemote(): bool
    {
        return $this->remotePath !== null;
    }
}
