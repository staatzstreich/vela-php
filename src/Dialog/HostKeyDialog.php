<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Config\Profile;

/** Mirrors vela's src/app.rs HostKeyDialog. */
final class HostKeyDialog
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $fingerprint,
        public readonly string $keyType,
        public readonly string $keyBytes,
        public readonly Profile $profile,
        public readonly ?string $password,
    ) {
    }
}
