<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Ui\TextInput;

/** Mirrors vela's src/app.rs RenameDialog. */
final class RenameDialog
{
    public TextInput $input;

    public function __construct(public readonly PanelSide $side, public readonly string $original)
    {
        $this->input = new TextInput($original, cursorAtEnd: true);
    }
}
