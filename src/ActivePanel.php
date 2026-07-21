<?php

declare(strict_types=1);

namespace Vela;

/** Mirrors vela's src/app.rs ActivePanel enum. */
enum ActivePanel
{
    case Left;
    case Right;

    public function toggle(): self
    {
        return $this === self::Left ? self::Right : self::Left;
    }
}
