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
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_help_dialog(). Covers what's actually ported so far. */
final class HelpDialogRenderer
{
    private const SHORTCUTS = [
        ['Tab', 'Panel wechseln'],
        ['↑ ↓', 'Navigieren'],
        ['Enter', 'Verzeichnis öffnen'],
        ['Backspace', 'Verzeichnis nach oben'],
        ['Leertaste', 'Markieren'],
        ['*', 'Alle markieren/entmarkieren'],
        ['/', 'Suche im aktiven Panel (jump-to-match)'],
        ['n / N', 'Nächster / vorheriger Treffer'],
        ['F2', 'Umbenennen'],
        ['F3', 'Trennen'],
        ['F4', 'Im Editor bearbeiten'],
        ['F5', 'Hochladen'],
        ['F6', 'Herunterladen'],
        ['Esc (bei Transfer)', 'Transfer abbrechen'],
        ['F7', 'Verzeichnis erstellen'],
        ['F8', 'Löschen'],
        ['F9 / p', 'Profile verwalten'],
        ['!', 'Shell-Befehl ausführen'],
        ['t', 'Remote-Datei tailen'],
        ['v', 'Bildvorschau'],
        ['Ctrl+T', 'Theme wechseln'],
        ['Ctrl+U / Ctrl+S', 'Panels tauschen'],
        ['F1', 'Diese Hilfe'],
        ['q / Esc', 'Beenden'],
    ];

    public static function build(Theme $theme): Widget
    {
        $items = array_map(
            static fn (array $s): ListItem => ListItem::new(Text::fromLine(Line::fromSpans(
                new Span(Format::padRight($s[0], 16), Style::default()->fg($theme->dialogActiveBorder)->addModifier(Modifier::BOLD)),
                new Span(" {$s[1]}", Style::default()->fg($theme->textPrimary)),
            ))),
            self::SHORTCUTS,
        );

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets(
                ListWidget::default()->items(...$items),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['F1 / Esc' => 'Schließen'], $theme))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Tastaturkürzel — F1 / Esc zum Schließen '))
            ->borderStyle(Style::default()->fg($theme->dialogActiveBorder))
            ->widget($body);

        return new CenteredBox(60, 85, $block);
    }
}
