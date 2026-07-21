<?php

declare(strict_types=1);

namespace Vela\Transfer;

/** Mirrors vela's src/transfer/queue.rs TransferState. */
enum TransferState
{
    case Running;
    case Done;
    case Failed;
}
