<?php

declare(strict_types=1);

namespace Vela\Connection;

final class HostKeyMismatchException extends SftpException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct(
            "Host key mismatch for '{$host}' — possible MITM! " .
            'Remove the old entry from ~/.ssh/known_hosts to proceed.'
        );
    }
}
