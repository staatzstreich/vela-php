<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
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
use Vela\Dialog\ShellDialog;
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_shell_dialog() (dispatches by phase). */
final class ShellDialogRenderer
{
    public static function build(ShellDialog $dlg, string $cwd, Theme $theme): Widget
    {
        return $dlg->output === null ? self::buildInput($dlg, $cwd, $theme) : self::buildOutput($dlg, $theme);
    }

    private static function buildInput(ShellDialog $dlg, string $cwd, Theme $theme): Widget
    {
        $textStyle = Style::default()->fg($theme->textPrimary);
        $cursorStyle = Style::default()->bg($theme->shellCursorBg)->fg($theme->shellCursorFg);
        $inputLine = TextInputRenderer::line($dlg->input, $textStyle, $cursorStyle);

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1), Constraint::length(1), Constraint::length(1))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    new Span(' Befehl:', Style::default()->fg($theme->shellLabel))
                ))),
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    Span::fromString(' '),
                    ...$inputLine->spans,
                ))),
                ParagraphWidget::fromText(Text::fromString('')),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Enter' => 'Ausführen', 'Esc' => 'Abbrechen'], $theme))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(" Shell  {$cwd}  "))
            ->borderStyle(Style::default()->fg($theme->dialogWarningBorder))
            ->widget($body);

        return new CenteredBox(70, 25, $block);
    }

    private static function buildOutput(ShellDialog $dlg, Theme $theme): Widget
    {
        $lines = array_map(
            static fn (string $l): Line => Line::fromSpans(new Span($l, Style::default()->fg($theme->textPrimary))),
            $dlg->output,
        );
        $paragraph = ParagraphWidget::fromText(Text::fromLines(...$lines))
            ->style(Style::default()->bg($theme->shellOutputBg));
        $paragraph->scroll = [$dlg->scroll, 0];

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets(
                $paragraph,
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints([
                    '↑↓' => 'Scrollen',
                    'PgUp/PgDn' => 'Seite',
                    'Esc' => 'Schließen',
                ], $theme))),
            );

        $borderColor = match ($dlg->exitCode) {
            0 => $theme->dialogSuccessBorder,
            null => $theme->dialogWarningBorder,
            default => $theme->dialogErrorBorder,
        };

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Ausgabe  Exit: ' . ($dlg->exitCode ?? '?') . '  '))
            ->borderStyle(Style::default()->fg($borderColor))
            ->widget($body);

        return new CenteredBox(85, 75, $block);
    }
}
