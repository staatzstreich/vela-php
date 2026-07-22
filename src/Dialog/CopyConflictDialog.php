<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Fs\FileEntry;

/**
 * Confirms overwriting/merging before a local-to-local copy (F5/F6 while
 * disconnected) when TransferEngine::findConflicts() found collisions. No
 * Rust original to mirror — local-to-local copy was never part of vela's
 * design. $sourceSide snapshots which panel the copy reads from at open
 * time (same reasoning as PanelSide elsewhere: the active panel could
 * change while the dialog is open, but this copy shouldn't).
 */
final class CopyConflictDialog
{
    /**
     * @param list<FileEntry> $entries          all entries queued for this copy
     * @param list<string>    $conflictingNames  subset of $entries' names already at $destDir
     */
    public function __construct(
        public readonly PanelSide $sourceSide,
        public readonly array $entries,
        public readonly string $sourceDir,
        public readonly string $destDir,
        public readonly array $conflictingNames,
    ) {
    }
}
