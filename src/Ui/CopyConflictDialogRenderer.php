<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\Dialog\CopyConflictDialog;
use Vela\Dialog\PanelSide;
use Vela\Theme\Theme;

/**
 * Confirmation dialog for local-to-local copy overwrites. Modeled directly
 * on DeleteDialogRenderer, but uses dialogWarningBorder rather than
 * dialogErrorBorder: this is a recoverable, expected overwrite confirmation,
 * not a destructive delete. No Rust original — see CopyConflictDialog's docblock.
 */
final class CopyConflictDialogRenderer
{
    public static function build(CopyConflictDialog $dlg, Theme $theme): Widget
    {
        $n = count($dlg->conflictingNames);
        $listLines = min($n, 6);
        $heightPct = min(25 + $listLines * 3, 80);
        $arrow = $dlg->sourceSide === PanelSide::Left ? '→' : '←';

        $title = $n === 1
            ? " Bereits vorhanden {$arrow} überschreiben? "
            : " {$n} Einträge bereits vorhanden {$arrow} überschreiben? ";

        $items = [];
        foreach (array_slice($dlg->conflictingNames, 0, 6) as $name) {
            $items[] = ListItem::new(Text::fromLine(Line::fromSpans(
                new Span('  ' . $name, Style::default()->fg($theme->textPrimary)),
            )));
        }
        if ($n > 6) {
            $items[] = ListItem::new(Text::fromLine(Line::fromSpans(
                new Span('  … und ' . ($n - 6) . ' weitere', Style::default()->fg($theme->textMuted)),
            )));
        }

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets(
                ListWidget::default()->items(...$items),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Y / Enter' => 'Überschreiben', 'N / Esc' => 'Abbrechen'], $theme))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString($title))
            ->borderStyle(Style::default()->fg($theme->dialogWarningBorder))
            ->widget($body);

        return new CenteredBox(55, $heightPct, $block);
    }
}
