<?php

declare(strict_types=1);

namespace Vela\Connection;

final class InsecureKeyPermissionsException extends SftpException
{
    public function __construct(public readonly string $path, public readonly int $mode)
    {
        parent::__construct(sprintf(
            'Insecure key file permissions for %s: %04o (expected 0600 or 0400)',
            $path,
            $mode,
        ));
    }
}
