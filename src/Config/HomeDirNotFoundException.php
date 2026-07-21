<?php

declare(strict_types=1);

namespace Vela\Config;

final class HomeDirNotFoundException extends ConfigException
{
    public function __construct()
    {
        parent::__construct('HOME directory not set — cannot locate profile config');
    }
}
