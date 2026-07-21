<?php

declare(strict_types=1);

namespace Vela\Transfer;

/**
 * Mirrors vela's src/transfer/queue.rs TransferProgress. Rust wraps this in
 * Arc<Mutex<>> so a background thread can write it while the render loop
 * reads it; our transfer runs synchronously in the same call stack, so a
 * plain mutable object is enough — no locking needed.
 */
final class TransferProgress
{
    public TransferState $state = TransferState::Running;

    public ?string $errorMessage = null;

    public string $currentFile = '';

    public int $bytesDone = 0;

    public int $bytesTotal = 0;

    public int $filesDone = 0;

    public int $filesTotal;

    public function __construct(int $filesTotal)
    {
        $this->filesTotal = max(1, $filesTotal);
    }

    /** 0.0-1.0 progress fraction for the current file. */
    public function fileFraction(): float
    {
        if ($this->bytesTotal === 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, $this->bytesDone / $this->bytesTotal));
    }

    /** 0.0-1.0 overall progress fraction (by file count). */
    public function overallFraction(): float
    {
        return min(1.0, max(0.0, $this->filesDone / $this->filesTotal));
    }
}
