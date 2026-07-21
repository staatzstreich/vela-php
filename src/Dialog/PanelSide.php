<?php

declare(strict_types=1);

namespace Vela\Dialog;

/**
 * Mirrors vela's src/app.rs PanelSide — deliberately distinct from
 * Vela\ActivePanel: rename/mkdir/delete dialogs snapshot which side they
 * operate on at open time, so later actions target that side even if the
 * user tabs to the other panel while the dialog is open.
 */
enum PanelSide
{
    case Left;
    case Right;
}
