<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Widget\Widget;

/**
 * A widget wrapper that renders its inner widget centered within a
 * percentage of the area it's given, mirroring vela's src/ui/dialogs.rs
 * centered_rect() + frame.render_widget(dialog, area) combo. Needs a
 * matching CenteredBoxRenderer registered on the Display (php-tui has no
 * per-call target-area API like ratatui's Frame::render_widget — every
 * widget in a frame shares one Area, so "where a widget draws" has to be
 * computed by the widget's own renderer instead).
 */
final class CenteredBox implements Widget
{
    public function __construct(
        public readonly int $percentWidth,
        public readonly int $percentHeight,
        public readonly Widget $inner,
    ) {
    }
}
