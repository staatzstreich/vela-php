<?php

declare(strict_types=1);

namespace Vela\Dialog;

/** Mirrors vela's src/app.rs DeleteDialog. */
final class DeleteDialog
{
    /** @param array<int,array{name:string,isDir:bool}> $entries */
    public function __construct(
        public readonly PanelSide $side,
        public readonly array $entries,
    ) {
    }
}
