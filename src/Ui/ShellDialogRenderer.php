<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
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

/** Mirrors vela's src/ui/dialogs.rs render_shell_dialog() (dispatches by phase). */
final class ShellDialogRenderer
{
    public static function build(ShellDialog $dlg, string $cwd): Widget
    {
        return $dlg->output === null ? self::buildInput($dlg, $cwd) : self::buildOutput($dlg);
    }

    private static function buildInput(ShellDialog $dlg, string $cwd): Widget
    {
        $textStyle = Style::default()->fg(AnsiColor::White);
        $cursorStyle = Style::default()->bg(AnsiColor::White)->fg(AnsiColor::Black);
        $inputLine = TextInputRenderer::line($dlg->input, $textStyle, $cursorStyle);

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1), Constraint::length(1), Constraint::length(1))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    new Span(' Befehl:', Style::default()->fg(AnsiColor::Yellow))
                ))),
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    Span::fromString(' '),
                    ...$inputLine->spans,
                ))),
                ParagraphWidget::fromText(Text::fromString('')),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Enter' => 'Ausführen', 'Esc' => 'Abbrechen']))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(" Shell  {$cwd}  "))
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->widget($body);

        return new CenteredBox(70, 25, $block);
    }

    private static function buildOutput(ShellDialog $dlg): Widget
    {
        $lines = array_map(
            static fn (string $l): Line => Line::fromSpans(new Span($l, Style::default()->fg(AnsiColor::White))),
            $dlg->output,
        );
        $paragraph = ParagraphWidget::fromText(Text::fromLines(...$lines));
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
                ]))),
            );

        $borderColor = match ($dlg->exitCode) {
            0 => AnsiColor::Green,
            null => AnsiColor::Yellow,
            default => AnsiColor::Red,
        };

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Ausgabe  Exit: ' . ($dlg->exitCode ?? '?') . '  '))
            ->borderStyle(Style::default()->fg($borderColor))
            ->widget($body);

        return new CenteredBox(85, 75, $block);
    }
}
