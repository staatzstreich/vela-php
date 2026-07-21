<?php

declare(strict_types=1);

namespace Vela\Connection;

/**
 * Thrown when the server's host key isn't in ~/.ssh/known_hosts. Vela's
 * main.rs surfaces this as a HostKeyDialog asking the user to trust it —
 * kept as its own exception (with the raw key bytes needed to append a
 * known_hosts entry) for that reason once dialogs land.
 */
final class UnknownHostKeyException extends SftpException
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $fingerprint,
        public readonly string $keyType,
        public readonly string $keyBytes,
    ) {
        parent::__construct("Unknown host key for {$host}: {$fingerprint}");
    }
}
