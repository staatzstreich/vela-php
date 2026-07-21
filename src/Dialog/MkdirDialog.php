<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Ui\TextInput;

/** Mirrors vela's src/app.rs MkdirDialog. */
final class MkdirDialog
{
    public TextInput $input;

    public function __construct(public readonly PanelSide $side)
    {
        $this->input = new TextInput('');
    }
}
