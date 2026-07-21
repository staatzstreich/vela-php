<?php

declare(strict_types=1);

namespace Vela\Dialog;

/** Mirrors vela's src/app.rs PermissionFixDialog — offered when profiles.toml isn't mode 0600. */
final class PermissionFixDialog
{
    public function __construct(
        public readonly string $path,
        public readonly int $mode,
    ) {
    }
}
