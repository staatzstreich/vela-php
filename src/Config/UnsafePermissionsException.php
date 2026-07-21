<?php

declare(strict_types=1);

namespace Vela\Config;

/**
 * Thrown when profiles.toml is not mode 0600. Vela's main.rs surfaces this
 * as a dedicated PermissionFixDialog rather than a generic error — kept as
 * its own exception type here for the same reason once dialogs land.
 */
final class UnsafePermissionsException extends ConfigException
{
    public function __construct(public readonly string $path, public readonly int $mode)
    {
        parent::__construct(sprintf(
            'Unsafe file permissions on %s: %04o (expected 0600)',
            $path,
            $mode,
        ));
    }
}
