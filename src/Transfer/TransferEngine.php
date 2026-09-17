<?php

declare(strict_types=1);

namespace Vela\Transfer;

use RuntimeException;
use Throwable;
use Vela\Connection\SftpConnection;
use Vela\Fs\FileEntry;

/**
 * Mirrors vela's src/connection/sftp.rs upload_batch()/download_batch() and
 * their recursive helpers. Rust runs these on a background OS thread and
 * polls a shared Arc<Mutex<TransferProgress>> from the render loop; PHP has
 * no practical portable threading, so this runs synchronously in the
 * caller's own call stack instead (per the project roadmap) — $onTick lets
 * the caller redraw between chunks so the UI doesn't look frozen, but input
 * genuinely is blocked until the transfer finishes. Good enough for a
 * prototype; pcntl_fork is the escalation path if that proves unacceptable.
 * PHP-only addition (no Rust original): $onTick is also where the app
 * checks for a pending Esc keypress and sets TransferProgress::$cancelled —
 * tick() then throws TransferCancelledException to unwind cleanly out of
 * whatever chunk/file loop is in progress, see tick() below.
 */
final class TransferEngine
{
    /**
     * Upload $entries (already resolved from the local panel: marked, or
     * the single highlighted one) from $localBaseDir into $remoteDir.
     *
     * @param FileEntry[] $entries
     * @param ?callable(TransferProgress):void $onTick
     */
    public static function uploadBatch(
        SftpConnection $sftp,
        array $entries,
        string $localBaseDir,
        string $remoteDir,
        TransferProgress $progress,
        ?callable $onTick = null,
    ): void {
        try {
            foreach ($entries as $entry) {
                $local = self::joinLocal($localBaseDir, $entry->name);
                if (is_dir($local)) {
                    self::uploadDirRecursive($sftp, $local, $remoteDir, $progress, $onTick);
                } else {
                    self::uploadFile($sftp, $local, $remoteDir, $progress, $onTick);
                }
            }
            $progress->state = TransferState::Done;
        } catch (TransferCancelledException) {
            $progress->state = TransferState::Cancelled;
        } catch (Throwable $e) {
            $progress->state = TransferState::Failed;
            $progress->errorMessage = $e->getMessage();
        }
        self::tick($progress, $onTick);
    }

    /**
     * Download $entries (from the remote panel) from $remoteDir into $localDir.
     *
     * @param FileEntry[] $entries
     * @param ?callable(TransferProgress):void $onTick
     */
    public static function downloadBatch(
        SftpConnection $sftp,
        array $entries,
        string $remoteDir,
        string $localDir,
        TransferProgress $progress,
        ?callable $onTick = null,
    ): void {
        try {
            foreach ($entries as $entry) {
                $remote = $sftp->joinRemotePath($remoteDir, $entry->name);
                if ($sftp->isRemoteDir($remote) === true) {
                    self::downloadDirRecursive($sftp, $remote, $localDir, $progress, $onTick);
                } else {
                    self::downloadFile($sftp, $remote, $localDir, $progress, $onTick);
                }
            }
            $progress->state = TransferState::Done;
        } catch (TransferCancelledException) {
            $progress->state = TransferState::Cancelled;
        } catch (Throwable $e) {
            $progress->state = TransferState::Failed;
            $progress->errorMessage = $e->getMessage();
        }
        self::tick($progress, $onTick);
    }

    /** Total number of regular files under $path (recursive). Mirrors count_files(). */
    public static function countLocalFiles(string $path): int
    {
        if (is_file($path)) {
            return 1;
        }
        $names = @scandir($path);
        if ($names === false) {
            return 0;
        }

        $total = 0;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $total += self::countLocalFiles(self::joinLocal($path, $name));
        }

        return $total;
    }

    /** Total number of regular files under $path on the remote side. Mirrors count_sftp_files(). */
    public static function countRemoteFiles(SftpConnection $sftp, string $path): int
    {
        $isDir = $sftp->isRemoteDir($path);
        if ($isDir === null) {
            return 0;
        }
        if (!$isDir) {
            return 1;
        }

        $total = 0;
        foreach (array_keys($sftp->childNames($path)) as $name) {
            $total += self::countRemoteFiles($sftp, $sftp->joinRemotePath($path, $name));
        }

        return $total;
    }

    private static function uploadFile(SftpConnection $sftp, string $local, string $remoteDir, TransferProgress $progress, ?callable $onTick): void
    {
        $name = basename($local);
        $remotePath = $sftp->joinRemotePath($remoteDir, $name);
        $total = @filesize($local) ?: 0;

        $progress->currentFile = $name;
        $progress->bytesDone = 0;
        $progress->bytesTotal = $total;
        self::tick($progress, $onTick);

        $sftp->putFile($local, $remotePath, function (int $bytesSoFar) use ($progress, $total, $onTick): void {
            $progress->bytesDone = $total > 0 ? min($bytesSoFar, $total) : $bytesSoFar;
            self::tick($progress, $onTick);
        });

        $progress->filesDone++;
        self::tick($progress, $onTick);
    }

    private static function uploadDirRecursive(SftpConnection $sftp, string $localDir, string $remoteParent, TransferProgress $progress, ?callable $onTick): void
    {
        $remoteDir = $sftp->joinRemotePath($remoteParent, basename($localDir));
        $sftp->mkdirRemote($remoteDir);

        $names = @scandir($localDir) ?: [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = self::joinLocal($localDir, $name);
            if (is_dir($child)) {
                self::uploadDirRecursive($sftp, $child, $remoteDir, $progress, $onTick);
            } else {
                self::uploadFile($sftp, $child, $remoteDir, $progress, $onTick);
            }
        }
    }

    private static function downloadFile(SftpConnection $sftp, string $remote, string $localDir, TransferProgress $progress, ?callable $onTick): void
    {
        $name = basename($remote);
        $localPath = self::joinLocal($localDir, $name);
        // Best-effort, matches the Rust side's `.ok().and_then(...).unwrap_or(0)`:
        // if the server doesn't report a size, the byte counter still counts up,
        // it just can't show a percentage for this particular file.
        $total = $sftp->remoteFileSize($remote) ?? 0;

        $progress->currentFile = $name;
        $progress->bytesDone = 0;
        $progress->bytesTotal = $total;
        self::tick($progress, $onTick);

        $sftp->getFile($remote, $localPath, function (int $bytesSoFar) use ($progress, $total, $onTick): void {
            $progress->bytesDone = $total > 0 ? min($bytesSoFar, $total) : $bytesSoFar;
            self::tick($progress, $onTick);
        });

        $progress->filesDone++;
        self::tick($progress, $onTick);
    }

    private static function downloadDirRecursive(SftpConnection $sftp, string $remoteDir, string $localParent, TransferProgress $progress, ?callable $onTick): void
    {
        $localDir = self::joinLocal($localParent, basename($remoteDir));
        if (!is_dir($localDir)) {
            mkdir($localDir, 0755);
        }

        foreach ($sftp->childNames($remoteDir) as $name => $isDir) {
            $remoteChild = $sftp->joinRemotePath($remoteDir, $name);
            if ($isDir) {
                self::downloadDirRecursive($sftp, $remoteChild, $localDir, $progress, $onTick);
            } else {
                self::downloadFile($sftp, $remoteChild, $localDir, $progress, $onTick);
            }
        }
    }

    /**
     * Copy $entries (already resolved from the source local panel: marked,
     * or the single highlighted one) from $sourceDir into $destDir — both
     * purely local paths, no SftpConnection involved. Unlike upload_batch()/
     * download_batch(), there's no Rust original to mirror: local-to-local
     * copy was never implemented in vela, this is a PHP-only addition.
     * Existing files at the destination are silently overwritten and
     * existing directories are merged into (see copyDirRecursive()) — the
     * caller is expected to have already confirmed that via findConflicts()
     * before calling this, the same way uploadActive()/downloadActive()
     * never ask before overwriting a remote file either.
     *
     * @param list<FileEntry> $entries
     * @param ?callable(TransferProgress):void $onTick
     */
    public static function copyBatch(
        array $entries,
        string $sourceDir,
        string $destDir,
        TransferProgress $progress,
        ?callable $onTick = null,
    ): void {
        try {
            if (self::sameDirectory($sourceDir, $destDir)) {
                throw new RuntimeException('Quelle und Ziel sind dasselbe Verzeichnis');
            }
            foreach ($entries as $entry) {
                $source = self::joinLocal($sourceDir, $entry->name);
                if (is_dir($source) && !is_link($source) && self::isWithin($destDir, $source)) {
                    throw new RuntimeException("'{$entry->name}' kann nicht in sein eigenes Unterverzeichnis kopiert werden");
                }
                if (is_link($source)) {
                    self::copySymlink($source, $destDir, $progress, $onTick);
                } elseif (is_dir($source)) {
                    self::copyDirRecursive($source, $destDir, $progress, $onTick);
                } else {
                    self::copyFile($source, $destDir, $progress, $onTick);
                }
            }
            $progress->state = TransferState::Done;
        } catch (TransferCancelledException) {
            $progress->state = TransferState::Cancelled;
        } catch (Throwable $e) {
            $progress->state = TransferState::Failed;
            $progress->errorMessage = $e->getMessage();
        }
        self::tick($progress, $onTick);
    }

    /**
     * Names among $entries that already exist directly inside $destDir —
     * used by App to decide whether to show a confirmation dialog before
     * calling copyBatch(). Top-level only: overwriting is a per-top-level-
     * entry decision, matching how copyDirRecursive() merges nested
     * directories without re-asking per nested file once the user has
     * confirmed once.
     *
     * @param list<FileEntry> $entries
     * @return list<string>
     */
    public static function findConflicts(array $entries, string $destDir): array
    {
        $conflicts = [];
        foreach ($entries as $entry) {
            if (file_exists(self::joinLocal($destDir, $entry->name))) {
                $conflicts[] = $entry->name;
            }
        }

        return $conflicts;
    }

    private static function copyFile(string $source, string $destDir, TransferProgress $progress, ?callable $onTick): void
    {
        $name = basename($source);
        $dest = self::joinLocal($destDir, $name);
        if (is_dir($dest)) {
            throw new RuntimeException("Ziel '{$dest}' ist ein Verzeichnis, Quelle eine Datei");
        }

        $progress->currentFile = $name;
        $progress->bytesDone = 0;
        $progress->bytesTotal = @filesize($source) ?: 0;
        self::tick($progress, $onTick);

        if (!copy($source, $dest)) {
            throw new RuntimeException("Kopieren fehlgeschlagen: {$source}");
        }

        $progress->bytesDone = $progress->bytesTotal;
        $progress->filesDone++;
        self::tick($progress, $onTick);
    }

    /**
     * Any symlink — to a file or a directory — is recreated as a fresh
     * symlink, never followed/recursed into. Mirrors
     * App::deleteLocalRecursive()'s `is_dir($path) && !is_link($path)`
     * convention: a symlink is always handled as its own thing, regardless
     * of what it points at.
     */
    private static function copySymlink(string $source, string $destDir, TransferProgress $progress, ?callable $onTick): void
    {
        $name = basename($source);
        $dest = self::joinLocal($destDir, $name);
        if (is_dir($dest) && !is_link($dest)) {
            throw new RuntimeException("Ziel '{$dest}' ist ein Verzeichnis, Quelle ein Symlink");
        }

        $progress->currentFile = $name;
        $progress->bytesDone = 0;
        $progress->bytesTotal = 0;
        self::tick($progress, $onTick);

        if (is_link($dest) || file_exists($dest)) {
            unlink($dest);
        }
        $target = readlink($source);
        if ($target === false || !symlink($target, $dest)) {
            throw new RuntimeException("Symlink konnte nicht erstellt werden: {$dest}");
        }

        $progress->filesDone++;
        self::tick($progress, $onTick);
    }

    private static function copyDirRecursive(string $sourceDir, string $destParent, TransferProgress $progress, ?callable $onTick): void
    {
        $destDir = self::joinLocal($destParent, basename($sourceDir));
        if (file_exists($destDir) && !is_dir($destDir)) {
            throw new RuntimeException("Ziel '{$destDir}' ist kein Verzeichnis, Quelle aber schon");
        }
        if (!is_dir($destDir) && !mkdir($destDir, 0755)) {
            throw new RuntimeException("Verzeichnis konnte nicht erstellt werden: {$destDir}");
        }

        $names = @scandir($sourceDir) ?: [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = self::joinLocal($sourceDir, $name);
            if (is_link($child)) {
                self::copySymlink($child, $destDir, $progress, $onTick);
            } elseif (is_dir($child)) {
                self::copyDirRecursive($child, $destDir, $progress, $onTick);
            } else {
                self::copyFile($child, $destDir, $progress, $onTick);
            }
        }
    }

    private static function sameDirectory(string $a, string $b): bool
    {
        return rtrim((string) (@realpath($a) ?: $a), '/') === rtrim((string) (@realpath($b) ?: $b), '/');
    }

    /** True if $path is $ancestorCandidate itself, or nested inside it. */
    private static function isWithin(string $path, string $ancestorCandidate): bool
    {
        $pathReal = rtrim((string) (@realpath($path) ?: $path), '/');
        $ancestorReal = rtrim((string) (@realpath($ancestorCandidate) ?: $ancestorCandidate), '/');

        return $pathReal === $ancestorReal || str_starts_with($pathReal . '/', $ancestorReal . '/');
    }

    /**
     * The guard on $progress->state here matters: this is also the final
     * tick called right after the catch block above sets Done/Cancelled/
     * Failed, and that trailing call must not itself throw — it's outside
     * the try/catch, so an unguarded throw here would escape uploadBatch()/
     * downloadBatch()/copyBatch() uncaught.
     */
    private static function tick(TransferProgress $progress, ?callable $onTick): void
    {
        if ($onTick !== null) {
            $onTick($progress);
        }
        if ($progress->cancelled && $progress->state === TransferState::Running) {
            throw new TransferCancelledException();
        }
    }

    private static function joinLocal(string $base, string $name): string
    {
        return $base === '/' ? '/' . $name : rtrim($base, '/') . '/' . $name;
    }
}
