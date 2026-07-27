<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\ImageMagick\Widget\ImageWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\Dialog\ImagePreviewDialog;
use Vela\Theme\Theme;

/**
 * Quick image preview overlay (v key). No Rust original — see
 * ImagePreviewDialog's docblock. Deliberately no custom "Imagick not
 * installed" fallback: php-tui's own ImageRenderer/ImagePainter already
 * draw a graceful placeholder in that case, so this just always renders
 * ImageWidget and trusts php-tui to degrade on its own.
 */
final class ImagePreviewDialogRenderer
{
    public static function build(ImagePreviewDialog $dlg, Theme $theme): Widget
    {
        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets(
                ImageWidget::fromPath($dlg->previewPath),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Esc / Q' => 'Schließen'], $theme))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(" {$dlg->entryName} "))
            ->borderStyle(Style::default()->fg($theme->dialogActiveBorder))
            ->widget($body);

        return new CenteredBox(70, 70, $block);
    }
}
