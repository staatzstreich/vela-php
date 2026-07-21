<?php

declare(strict_types=1);

namespace Vela\Connection;

final class KeyNotFoundException extends SftpException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("Key file not found: {$path}");
    }
}
