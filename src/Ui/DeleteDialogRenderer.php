<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\Dialog\DeleteDialog;
use Vela\Dialog\PanelSide;
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_delete_dialog(). */
final class DeleteDialogRenderer
{
    public static function build(DeleteDialog $dlg, Theme $theme): Widget
    {
        $n = count($dlg->entries);
        $listLines = min($n, 6);
        $heightPct = min(25 + $listLines * 3, 80);

        $sideLabel = $dlg->side === PanelSide::Left ? 'Lokal' : 'Remote';
        if ($n === 1) {
            $kind = $dlg->entries[0]['isDir'] ? 'Verzeichnis' : 'Datei';
            $title = " {$sideLabel} {$kind} löschen? ";
        } else {
            $title = " {$sideLabel} — {$n} Einträge löschen? ";
        }

        $items = [];
        foreach (array_slice($dlg->entries, 0, 6) as $entry) {
            $icon = $entry['isDir'] ? '▶ ' : '  ';
            $iconStyle = $entry['isDir']
                ? Style::default()->fg($theme->directoryIcon)->addModifier(Modifier::BOLD)
                : Style::default()->fg($theme->fileName);
            $items[] = ListItem::new(Text::fromLine(Line::fromSpans(
                new Span(" {$icon}", $iconStyle),
                new Span($entry['name'], Style::default()->fg($theme->textPrimary)->addModifier(Modifier::BOLD)),
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
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Y / Enter' => 'Löschen', 'N / Esc' => 'Abbrechen'], $theme))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString($title))
            ->borderStyle(Style::default()->fg($theme->dialogErrorBorder))
            ->widget($body);

        return new CenteredBox(55, $heightPct, $block);
    }
}
