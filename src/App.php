<?php

declare(strict_types=1);

namespace Vela;

use Throwable;
use Vela\Connection\SftpConnection;
use Vela\Fs\FileEntry;
use Vela\Fs\PanelState;

/**
 * Central app state. Mirrors vela's src/app.rs App struct — reduced to
 * what's needed for dual-panel browsing plus SFTP connect (no dialogs yet,
 * so a connection is currently only made via bin/vela.php's --profile flag).
 */
final class App
{
    public bool $running = true;

    public ActivePanel $active = ActivePanel::Left;

    public ?string $statusMessage = null;

    public PanelState $left;

    public PanelState $right;

    public ?SftpConnection $sftp = null;

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

    /** @param FileEntry[] $entries */
    private function setRemoteListing(array $entries): void
    {
        $this->right->path = $this->sftp->remotePath;
        $this->right->entries = $entries;
        $this->right->selected = 0;
        $this->right->marked = [];
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
