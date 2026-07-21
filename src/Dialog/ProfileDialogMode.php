<?php

declare(strict_types=1);

namespace Vela\Dialog;

/** Mirrors vela's src/app.rs ProfileDialogMode (field/index payloads live on ProfileDialog instead). */
enum ProfileDialogMode
{
    case ListMode;
    case New;
    case Edit;
    case ConfirmDelete;
}
