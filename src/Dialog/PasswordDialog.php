<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Config\Profile;
use Vela\Ui\TextInput;

/** Mirrors vela's src/app.rs PasswordDialog. */
final class PasswordDialog
{
    public TextInput $input;

    public ?string $error = null;

    public function __construct(public readonly Profile $profile)
    {
        $this->input = new TextInput('');
    }
}
