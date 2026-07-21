<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Widget\WidgetRenderer;

/**
 * Must be registered on the Display via ->addWidgetRenderer() — it isn't
 * part of php-tui's CoreExtension. Overlaying works because BlockWidget
 * always builds a fresh blank sub-buffer for its inner content and pastes
 * the whole thing back (see BlockRenderer::render() -> Buffer::putBuffer()),
 * so a bordered dialog Block naturally clears whatever panel content was
 * underneath it — no equivalent of ratatui's Clear widget needed.
 */
final class CenteredBoxRenderer implements WidgetRenderer
{
    public function render(WidgetRenderer $renderer, Widget $widget, Buffer $buffer, Area $area): void
    {
        if (!$widget instanceof CenteredBox) {
            return;
        }

        $target = self::centeredRect($widget->percentWidth, $widget->percentHeight, $area);
        $renderer->render($renderer, $widget->inner, $buffer, $target);
    }

    /** Mirrors vela's src/ui/dialogs.rs centered_rect(). */
    public static function centeredRect(int $percentX, int $percentY, Area $area): Area
    {
        $rows = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints([
                Constraint::percentage(intdiv(100 - $percentY, 2)),
                Constraint::percentage($percentY),
                Constraint::percentage(intdiv(100 - $percentY, 2)),
            ])
            ->split($area);

        $cols = Layout::default()
            ->direction(Direction::Horizontal)
            ->constraints([
                Constraint::percentage(intdiv(100 - $percentX, 2)),
                Constraint::percentage($percentX),
                Constraint::percentage(intdiv(100 - $percentX, 2)),
            ])
            ->split($rows->get(1));

        return $cols->get(1);
    }
}
