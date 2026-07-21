<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\Dialog\MkdirDialog;
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_mkdir_dialog(). */
final class MkdirDialogRenderer
{
    public static function build(MkdirDialog $dlg, Theme $theme): Widget
    {
        $textStyle = Style::default()->fg($theme->textPrimary);
        $cursorStyle = Style::default()->bg($theme->cursorBg)->fg($theme->cursorFg);
        $inputLine = TextInputRenderer::line($dlg->input, $textStyle, $cursorStyle);

        $inputBlock = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Name '))
            ->borderStyle(Style::default()->fg($theme->dialogActiveBorder))
            ->widget(ParagraphWidget::fromText(Text::fromLine($inputLine)));

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(3), Constraint::length(1), Constraint::min(0))
            ->widgets(
                $inputBlock,
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Enter' => 'Erstellen', 'Esc' => 'Abbrechen'], $theme))),
                ParagraphWidget::fromText(Text::fromString('')),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Verzeichnis erstellen '))
            ->borderStyle(Style::default()->fg($theme->dialogWarningBorder))
            ->widget($body);

        return new CenteredBox(50, 30, $block);
    }
}
