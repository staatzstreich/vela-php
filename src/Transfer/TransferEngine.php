<?php

declare(strict_types=1);

namespace Vela\Transfer;

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

    private static function tick(TransferProgress $progress, ?callable $onTick): void
    {
        if ($onTick !== null) {
            $onTick($progress);
        }
    }

    private static function joinLocal(string $base, string $name): string
    {
        return $base === '/' ? '/' . $name : rtrim($base, '/') . '/' . $name;
    }
}
