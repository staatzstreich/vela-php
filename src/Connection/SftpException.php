<?php

declare(strict_types=1);

namespace Vela\Connection;

use RuntimeException;

/** Mirrors vela's src/connection/sftp.rs SftpError. */
class SftpException extends RuntimeException
{
}
