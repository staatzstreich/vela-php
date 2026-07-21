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
use Vela\Dialog\PasswordDialog;

/** Mirrors vela's src/ui/dialogs.rs render_password_dialog(). */
final class PasswordDialogRenderer
{
    public static function build(PasswordDialog $dlg): Widget
    {
        $hasError = $dlg->error !== null;

        $textStyle = Style::default()->fg(AnsiColor::White);
        $cursorStyle = Style::default()->bg(AnsiColor::White)->fg(AnsiColor::Black);
        $inputLine = TextInputRenderer::line($dlg->input, $textStyle, $cursorStyle, masked: true);

        $inputBlock = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Passwort '))
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->widget(ParagraphWidget::fromText(Text::fromLine($inputLine)));

        $errorLine = $hasError
            ? Line::fromSpans(new Span('✗ ' . $dlg->error, Style::default()->fg(AnsiColor::Red)))
            : Line::fromString('');

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(3), Constraint::length(1), Constraint::min(0), Constraint::length(1))
            ->widgets(
                $inputBlock,
                ParagraphWidget::fromText(Text::fromLine($errorLine)),
                ParagraphWidget::fromText(Text::fromString('')),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Enter' => 'Verbinden', 'Esc' => 'Abbrechen']))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(" Passwort für {$dlg->profile->user}@{$dlg->profile->host} "))
            ->borderStyle(Style::default()->fg($hasError ? AnsiColor::Red : AnsiColor::Yellow))
            ->widget($body);

        return new CenteredBox(50, 40, $block);
    }
}
