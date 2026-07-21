<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Config\ProfileStore;

/** Mirrors vela's src/app.rs ProfileDialog. */
final class ProfileDialog
{
    public ProfileDialogMode $mode = ProfileDialogMode::ListMode;

    public int $listSelected = 0;

    public NewProfileForm $form;

    public int $field = 0;

    public ?int $editIndex = null;

    public ?int $deleteIndex = null;

    public function __construct(
        public readonly ProfileStore $store,
        public readonly ?int $activeProfile = null,
    ) {
        $this->form = new NewProfileForm();
    }

    public function listMoveUp(): void
    {
        if ($this->listSelected > 0) {
            $this->listSelected--;
        }
    }

    public function listMoveDown(): void
    {
        if ($this->listSelected + 1 < count($this->store->profiles)) {
            $this->listSelected++;
        }
    }
}
