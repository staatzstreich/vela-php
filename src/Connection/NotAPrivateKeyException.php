<?php

declare(strict_types=1);

namespace Vela\Connection;

/**
 * Thrown when key_path points at a file PublicKeyLoader can parse, but
 * which turns out to hold a public key rather than a private one (e.g. a
 * misconfigured key_path pointing at id_rsa.pub) — login() needs the
 * private half to sign the handshake.
 */
final class NotAPrivateKeyException extends SftpException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("Not a private key: {$path}");
    }
}
