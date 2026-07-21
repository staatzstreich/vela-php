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
use Vela\Dialog\HostKeyDialog;
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_host_key_dialog(). */
final class HostKeyDialogRenderer
{
    public static function build(HostKeyDialog $dlg, Theme $theme): Widget
    {
        $lines = [
            Line::fromSpans(new Span('⚠   Unbekannter Host-Key!   ⚠', Style::default()->fg($theme->textWarning)->addModifier(Modifier::BOLD))),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Host:        ', Style::default()->fg($theme->textPrimary)),
                new Span("{$dlg->host}:{$dlg->port}", Style::default()->fg($theme->dialogActiveBorder)),
            ),
            Line::fromSpans(
                new Span('Key-Typ:     ', Style::default()->fg($theme->textPrimary)),
                new Span($dlg->keyType, Style::default()->fg($theme->textSecondary)),
            ),
            Line::fromSpans(
                new Span('Fingerprint: ', Style::default()->fg($theme->textPrimary)),
                new Span($dlg->fingerprint, Style::default()->fg($theme->textPrimary)->addModifier(Modifier::BOLD)),
            ),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Dieser Host ist ', Style::default()->fg($theme->textPrimary)),
                new Span('nicht', Style::default()->fg($theme->textDanger)->addModifier(Modifier::BOLD)),
                new Span(' in ~/.ssh/known_hosts vorhanden.', Style::default()->fg($theme->textPrimary)),
            ),
            Line::fromSpans(new Span('Bitte prüfe den Fingerprint aus einer vertrauenswürdigen Quelle.', Style::default()->fg($theme->textPrimary))),
            Line::fromString(''),
            Line::fromSpans(
                new Span('Y / Enter', Style::default()->fg($theme->textSuccess)->addModifier(Modifier::BOLD)),
                new Span(' — Vertrauen und zu known_hosts hinzufügen', Style::default()->fg($theme->textPrimary)),
            ),
            Line::fromSpans(
                new Span('N / Esc', Style::default()->fg($theme->textDanger)->addModifier(Modifier::BOLD)),
                new Span('   — Verbindung abbrechen', Style::default()->fg($theme->textPrimary)),
            ),
        ];

        $body = ParagraphWidget::fromText(Text::fromLines(...$lines))
            ->wrap(Wrap::WordTrimmed)
            ->alignment(HorizontalAlignment::Center);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Unbekannter Host-Key '))
            ->borderStyle(Style::default()->fg($theme->dialogWarningBorder))
            ->widget($body);

        return new CenteredBox(65, 55, $block);
    }
}
