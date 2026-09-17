<?php

declare(strict_types=1);

namespace Vela\Transfer;

/** Mirrors vela's src/transfer/queue.rs TransferState. */
enum TransferState
{
    case Running;
    case Done;
    case Failed;
    /**
     * User pressed Esc mid-transfer (TransferProgress::$cancelled). PHP-only
     * addition — vela (Rust) has no equivalent since its transfers run on a
     * background thread and cancel by dropping the Arc<Mutex<>> handle.
     */
    case Cancelled;
}
