<?php

declare(strict_types=1);

namespace Vela;

use Throwable;
use Vela\Connection\SftpConnection;
use Vela\Fs\FileEntry;
use Vela\Fs\PanelState;
use Vela\Transfer\TransferEngine;
use Vela\Transfer\TransferProgress;
use Vela\Transfer\TransferState;

/**
 * Central app state. Mirrors vela's src/app.rs App struct — reduced to
 * what's needed for dual-panel browsing, SFTP connect, and transfers (no
 * dialogs yet, so a connection is currently only made via bin/vela.php's
 * --profile flag).
 */
final class App
{
    public bool $running = true;

    public ActivePanel $active = ActivePanel::Left;

    public ?string $statusMessage = null;

    public PanelState $left;

    public PanelState $right;

    public ?SftpConnection $sftp = null;

    public ?TransferProgress $activeTransfer = null;

    public string $activeTransferVerb = '';

    public function __construct(string $leftPath, string $rightPath)
    {
        $this->left = new PanelState($leftPath);
        $this->right = new PanelState($rightPath);
        $this->left->loadLocal();
        $this->right->loadLocal();
    }

    public function isConnected(): bool
    {
        return $this->sftp !== null;
    }

    /** Right panel switches from local browsing to the remote listing. */
    public function attachSftp(SftpConnection $sftp): void
    {
        $this->sftp = $sftp;
        $this->right->path = $sftp->remotePath;
        $this->right->entries = $sftp->listDir();
        $this->right->selected = 0;
        $this->right->marked = [];
    }

    public function activePanel(): PanelState
    {
        return $this->active === ActivePanel::Left ? $this->left : $this->right;
    }

    public function togglePanel(): void
    {
        $this->active = $this->active->toggle();
    }

    public function quit(): void
    {
        $this->running = false;
    }

    public function enterActive(): void
    {
        $this->tryRun(function (): void {
            if ($this->active === ActivePanel::Right && $this->sftp !== null) {
                $entry = $this->right->entries[$this->right->selected] ?? null;
                if ($entry === null || !$entry->isDir) {
                    return;
                }
                $this->setRemoteListing($this->sftp->enterDir($entry->name));
            } else {
                $this->activePanel()->enterSelected();
            }
        });
    }

    public function goUpActive(): void
    {
        $this->tryRun(function (): void {
            if ($this->active === ActivePanel::Right && $this->sftp !== null) {
                $this->setRemoteListing($this->sftp->goUp());
            } else {
                $this->activePanel()->goUp();
            }
        });
    }

    /**
     * Upload the marked left-panel entries (or the highlighted entry when
     * nothing is marked) to the current remote directory. No-op when not
     * connected. Runs synchronously — $onTick is called between chunks so
     * the caller can redraw a progress bar; input stays blocked meanwhile.
     */
    public function uploadActive(?callable $onTick = null): void
    {
        if ($this->sftp === null) {
            return;
        }
        $entries = self::entriesToTransfer($this->left);
        if ($entries === []) {
            return;
        }

        $localBase = $this->left->path;
        $remoteDir = $this->right->path;
        $this->left->clearMarks();

        $totalFiles = max(1, array_sum(array_map(
            fn (FileEntry $e): int => TransferEngine::countLocalFiles(self::joinLocal($localBase, $e->name)),
            $entries,
        )));

        $progress = new TransferProgress($totalFiles);
        $this->activeTransfer = $progress;
        $this->activeTransferVerb = 'Upload';

        TransferEngine::uploadBatch($this->sftp, $entries, $localBase, $remoteDir, $progress, $onTick);

        $this->activeTransfer = null;
        if ($progress->state === TransferState::Done) {
            $this->statusMessage = 'Upload abgeschlossen';
            $this->tryRun(fn () => $this->setRemoteListing($this->sftp->listDir()));
        } else {
            $this->statusMessage = 'Upload fehlgeschlagen: ' . ($progress->errorMessage ?? 'unbekannter Fehler');
        }
    }

    /**
     * Download the marked right-panel entries (or the highlighted entry
     * when nothing is marked) into the current local directory.
     */
    public function downloadActive(?callable $onTick = null): void
    {
        if ($this->sftp === null) {
            return;
        }
        $entries = self::entriesToTransfer($this->right);
        if ($entries === []) {
            return;
        }

        $remoteDir = $this->right->path;
        $localDir = $this->left->path;
        $this->right->clearMarks();

        $totalFiles = max(1, array_sum(array_map(
            fn (FileEntry $e): int => TransferEngine::countRemoteFiles($this->sftp, $this->sftp->joinRemotePath($remoteDir, $e->name)),
            $entries,
        )));

        $progress = new TransferProgress($totalFiles);
        $this->activeTransfer = $progress;
        $this->activeTransferVerb = 'Download';

        TransferEngine::downloadBatch($this->sftp, $entries, $remoteDir, $localDir, $progress, $onTick);

        $this->activeTransfer = null;
        if ($progress->state === TransferState::Done) {
            $this->statusMessage = 'Download abgeschlossen';
            $this->tryRun(fn () => $this->left->loadLocal());
        } else {
            $this->statusMessage = 'Download fehlgeschlagen: ' . ($progress->errorMessage ?? 'unbekannter Fehler');
        }
    }

    /** @return FileEntry[] */
    private static function entriesToTransfer(PanelState $panel): array
    {
        if ($panel->marked === []) {
            $entry = $panel->entries[$panel->selected] ?? null;

            return $entry !== null && $entry->name !== '..' ? [$entry] : [];
        }

        $indices = array_keys($panel->marked);
        sort($indices);

        $entries = [];
        foreach ($indices as $i) {
            $entry = $panel->entries[$i] ?? null;
            if ($entry !== null && $entry->name !== '..') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @param FileEntry[] $entries */
    private function setRemoteListing(array $entries): void
    {
        $this->right->path = $this->sftp->remotePath;
        $this->right->entries = $entries;
        $this->right->selected = 0;
        $this->right->marked = [];
    }

    private static function joinLocal(string $base, string $name): string
    {
        return $base === '/' ? '/' . $name : rtrim($base, '/') . '/' . $name;
    }

    private function tryRun(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->statusMessage = $e->getMessage();
        }
    }
}
