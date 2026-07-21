<?php

declare(strict_types=1);

namespace Vela;

use Throwable;
use Vela\Fs\PanelState;

/**
 * Central app state. Mirrors vela's src/app.rs App struct — reduced to
 * what's needed for local dual-panel browsing (no SFTP/dialogs yet).
 */
final class App
{
    public bool $running = true;

    public ActivePanel $active = ActivePanel::Left;

    public ?string $statusMessage = null;

    public PanelState $left;

    public PanelState $right;

    public function __construct(string $leftPath, string $rightPath)
    {
        $this->left = new PanelState($leftPath);
        $this->right = new PanelState($rightPath);
        $this->left->loadLocal();
        $this->right->loadLocal();
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
        $this->tryRun(fn () => $this->activePanel()->enterSelected());
    }

    public function goUpActive(): void
    {
        $this->tryRun(fn () => $this->activePanel()->goUp());
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
