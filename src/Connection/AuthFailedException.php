<?php

declare(strict_types=1);

namespace Vela\Connection;

final class AuthFailedException extends SftpException
{
    public function __construct()
    {
        parent::__construct('Authentication failed');
    }
}
