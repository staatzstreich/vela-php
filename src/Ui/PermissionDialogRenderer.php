<?php

declare(strict_types=1);

namespace Vela\Ui;

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
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_permission_dialog(). */
final class PermissionDialogRenderer
{
    public static function build(PermissionFixDialog $dlg, Theme $theme): Widget
    {
        $lines = [
            Line::fromSpans(new Span('⚠   Warnung: Unsichere Berechtigungen!   ⚠', Style::default()->fg($theme->textWarning)->addModifier(Modifier::BOLD))),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Datei: ', Style::default()->fg($theme->textPrimary)),
                new Span($dlg->path, Style::default()->fg($theme->textInfo)),
            ),
            Line::fromSpans(
                new Span('Aktuelle Rechte: ', Style::default()->fg($theme->textPrimary)),
                new Span(sprintf('%04o', $dlg->mode), Style::default()->fg($theme->textDanger)),
            ),
            Line::fromSpans(
                new Span('Erforderlich: ', Style::default()->fg($theme->textPrimary)),
                new Span('0600', Style::default()->fg($theme->textSuccess)),
            ),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Andere Benutzer können die Datei ', Style::default()->fg($theme->textPrimary)),
                new Span('lesen', Style::default()->fg($theme->textDanger)->addModifier(Modifier::BOLD)),
                new Span(' oder ', Style::default()->fg($theme->textPrimary)),
                new Span('schreiben', Style::default()->fg($theme->textDanger)->addModifier(Modifier::BOLD)),
                new Span('.', Style::default()->fg($theme->textPrimary)),
            ),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Drücke ', Style::default()->fg($theme->textPrimary)),
                new Span('f', Style::default()->fg($theme->textSuccess)->addModifier(Modifier::BOLD)),
                new Span(' um Rechte auf 0600 zu setzen', Style::default()->fg($theme->textPrimary)),
            ),
            Line::fromSpans(
                new Span('Drücke ', Style::default()->fg($theme->textPrimary)),
                new Span('i', Style::default()->fg($theme->textInfo)->addModifier(Modifier::BOLD)),
                new Span(' oder ', Style::default()->fg($theme->textPrimary)),
                new Span('Esc', Style::default()->fg($theme->textInfo)->addModifier(Modifier::BOLD)),
                new Span(' um fortzufahren', Style::default()->fg($theme->textPrimary)),
            ),
        ];

        $body = ParagraphWidget::fromText(Text::fromLines(...$lines))
            ->wrap(Wrap::WordTrimmed)
            ->alignment(HorizontalAlignment::Center);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Berechtigungen '))
            ->borderStyle(Style::default()->fg($theme->dialogWarningBorder))
            ->widget($body);

        return new CenteredBox(60, 50, $block);
    }
}
