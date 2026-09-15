<?php

declare(strict_types=1);

namespace Vela;

/**
 * Incremental "jump to filename" search state ('/' key), vim-style. Mirrors
 * vela's src/app.rs SearchState.
 */
final class SearchState
{
    public function __construct(
        public string $query,
        /**
         * Selected index in the active panel when the search started — the
         * anchor incremental search recomputes from, and where Esc reverts to.
         */
        public readonly int $origin,
    ) {
    }
}
