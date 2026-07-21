<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Paragraph\Wrap;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use Vela\Dialog\PermissionFixDialog;

/** Mirrors vela's src/ui/dialogs.rs render_permission_dialog(). */
final class PermissionDialogRenderer
{
    public static function build(PermissionFixDialog $dlg): Widget
    {
        $lines = [
            Line::fromSpans(new Span('⚠   Warnung: Unsichere Berechtigungen!   ⚠', Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD))),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Datei: ', Style::default()->fg(AnsiColor::White)),
                new Span($dlg->path, Style::default()->fg(AnsiColor::Cyan)),
            ),
            Line::fromSpans(
                new Span('Aktuelle Rechte: ', Style::default()->fg(AnsiColor::White)),
                new Span(sprintf('%04o', $dlg->mode), Style::default()->fg(AnsiColor::Red)),
            ),
            Line::fromSpans(
                new Span('Erforderlich: ', Style::default()->fg(AnsiColor::White)),
                new Span('0600', Style::default()->fg(AnsiColor::Green)),
            ),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Andere Benutzer können die Datei ', Style::default()->fg(AnsiColor::White)),
                new Span('lesen', Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD)),
                new Span(' oder ', Style::default()->fg(AnsiColor::White)),
                new Span('schreiben', Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD)),
                new Span('.', Style::default()->fg(AnsiColor::White)),
            ),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Drücke ', Style::default()->fg(AnsiColor::White)),
                new Span('f', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                new Span(' um Rechte auf 0600 zu setzen', Style::default()->fg(AnsiColor::White)),
            ),
            Line::fromSpans(
                new Span('Drücke ', Style::default()->fg(AnsiColor::White)),
                new Span('i', Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)),
                new Span(' oder ', Style::default()->fg(AnsiColor::White)),
                new Span('Esc', Style::default()->fg(AnsiColor::Cyan)->addModifier(Modifier::BOLD)),
                new Span(' um fortzufahren', Style::default()->fg(AnsiColor::White)),
            ),
        ];

        $body = ParagraphWidget::fromText(Text::fromLines(...$lines))
            ->wrap(Wrap::WordTrimmed)
            ->alignment(HorizontalAlignment::Center);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Berechtigungen '))
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->widget($body);

        return new CenteredBox(60, 50, $block);
    }
}
