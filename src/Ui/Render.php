<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\ActivePanel;
use Vela\App;

/**
 * Top-level frame composition. Mirrors vela's src/ui/mod.rs render(),
 * minus dialogs/theming/transfer progress which come in later milestones.
 */
final class Render
{
    public static function build(App $app, Area $viewport): Widget
    {
        // Split eagerly (in addition to the GridWidget below, which does the
        // same solve again at render time) purely so panel content sizing
        // (name truncation/padding) can use the real column widths — mirrors
        // vela's own Layout::split() + Block::inner() combo in ui/mod.rs +
        // ui/panels.rs.
        $rows = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints([Constraint::min(0), Constraint::length(1)])
            ->split($viewport);

        $cols = Layout::default()
            ->direction(Direction::Horizontal)
            ->constraints([Constraint::percentage(50), Constraint::percentage(50)])
            ->split($rows->get(0));

        $leftBlock = PanelRenderer::renderPanel($cols->get(0), $app->left, $app->active === ActivePanel::Left, 'Local');
        $rightBlock = PanelRenderer::renderPanel($cols->get(1), $app->right, $app->active === ActivePanel::Right, 'Local');

        $panelsGrid = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::percentage(50), Constraint::percentage(50))
            ->widgets($leftBlock, $rightBlock);

        $hint = ParagraphWidget::fromText(Text::fromString(self::hintLine($app)))
            ->style(Style::default()->fg(AnsiColor::DarkGray));

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets($panelsGrid, $hint);
    }

    private static function hintLine(App $app): string
    {
        if ($app->statusMessage !== null) {
            return ' ' . $app->statusMessage;
        }

        return ' Tab wechseln | ↑↓ bewegen | Enter öffnen | Backspace hoch | Leertaste markieren | * alle markieren | q beenden';
    }
}
