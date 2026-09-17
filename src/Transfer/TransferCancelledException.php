<?php

declare(strict_types=1);

namespace Vela\Transfer;

use RuntimeException;

/**
 * Thrown from TransferEngine::tick() once TransferProgress::$cancelled has
 * been set (Esc during a running transfer), to unwind out of whatever
 * blocking phpseclib/local-copy call is in progress. Caught in
 * uploadBatch()/downloadBatch()/copyBatch() to set TransferState::Cancelled
 * instead of Failed.
 */
final class TransferCancelledException extends RuntimeException
{
}
