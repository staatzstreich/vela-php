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
use Vela\Dialog\HostKeyDialog;

/** Mirrors vela's src/ui/dialogs.rs render_host_key_dialog(). */
final class HostKeyDialogRenderer
{
    public static function build(HostKeyDialog $dlg): Widget
    {
        $lines = [
            Line::fromSpans(new Span('⚠   Unbekannter Host-Key!   ⚠', Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD))),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Host:        ', Style::default()->fg(AnsiColor::White)),
                new Span("{$dlg->host}:{$dlg->port}", Style::default()->fg(AnsiColor::Cyan)),
            ),
            Line::fromSpans(
                new Span('Key-Typ:     ', Style::default()->fg(AnsiColor::White)),
                new Span($dlg->keyType, Style::default()->fg(AnsiColor::Gray)),
            ),
            Line::fromSpans(
                new Span('Fingerprint: ', Style::default()->fg(AnsiColor::White)),
                new Span($dlg->fingerprint, Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD)),
            ),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Dieser Host ist ', Style::default()->fg(AnsiColor::White)),
                new Span('nicht', Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD)),
                new Span(' in ~/.ssh/known_hosts vorhanden.', Style::default()->fg(AnsiColor::White)),
            ),
            Line::fromSpans(new Span('Bitte prüfe den Fingerprint aus einer vertrauenswürdigen Quelle.', Style::default()->fg(AnsiColor::White))),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Y / Enter', Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)),
                new Span(' — Vertrauen und zu known_hosts hinzufügen', Style::default()->fg(AnsiColor::White)),
            ),
            Line::fromSpans(
                new Span('N / Esc', Style::default()->fg(AnsiColor::Red)->addModifier(Modifier::BOLD)),
                new Span('   — Verbindung abbrechen', Style::default()->fg(AnsiColor::White)),
            ),
        ];

        $body = ParagraphWidget::fromText(Text::fromLines(...$lines))
            ->wrap(Wrap::WordTrimmed)
            ->alignment(HorizontalAlignment::Center);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Unbekannter Host-Key '))
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->widget($body);

        return new CenteredBox(65, 55, $block);
    }
}
