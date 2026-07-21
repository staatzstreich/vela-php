<?php

declare(strict_types=1);

namespace Vela\Config;

/** Mirrors vela's src/config/profiles.rs AuthMethod (serde rename_all = "lowercase"). */
enum AuthMethod: string
{
    case Key = 'key';
    case Password = 'password';
}
