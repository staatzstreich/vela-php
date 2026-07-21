<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\Transfer\TransferProgress;

/**
 * Mirrors vela's src/ui/statusbar.rs render_transfer_bar(): a block-character
 * bar (█ filled / ░ empty) built from styled spans rather than a Gauge
 * widget, with a centered text label overlaid directly onto the bar
 * characters, plus a second row showing the current file.
 */
final class TransferBarRenderer
{
    public static function build(Area $area, TransferProgress $progress, string $verb, Color $barColor): Widget
    {
        $rows = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints([Constraint::length(1), Constraint::length(1)])
            ->split($area);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1))
            ->widgets(
                self::buildBar($rows->get(0), $progress, $verb, $barColor),
                self::buildFilenameRow($rows->get(1), $progress),
            );
    }

    private static function buildBar(Area $area, TransferProgress $progress, string $verb, Color $barColor): Widget
    {
        $width = $area->width;
        $fraction = $progress->overallFraction();
        $pct = (int) round($fraction * 100);
        $label = sprintf(' %s %d/%d — %d%% ', $verb, $progress->filesDone, $progress->filesTotal, $pct);

        $filled = min($width, (int) round($fraction * $width));
        $empty = max(0, $width - $filled);

        $barChars = array_merge(array_fill(0, $filled, '█'), array_fill(0, $empty, '░'));

        // Overlay the label, centered, directly onto the bar characters.
        $labelLen = min($width, mb_strlen($label));
        $padLeft = intdiv(max(0, $width - $labelLen), 2);
        for ($i = 0; $i < $labelLen; $i++) {
            $pos = $padLeft + $i;
            if ($pos < count($barChars)) {
                $barChars[$pos] = mb_substr($label, $i, 1);
            }
        }

        $filledStr = implode('', array_slice($barChars, 0, $filled));
        $emptyStr = implode('', array_slice($barChars, $filled));

        $line = Line::fromSpans(
            new Span($filledStr, Style::default()->fg(AnsiColor::Black)->bg($barColor)->addModifier(Modifier::BOLD)),
            new Span($emptyStr, Style::default()->fg($barColor)),
        );

        return ParagraphWidget::fromText(Text::fromLine($line));
    }

    private static function buildFilenameRow(Area $area, TransferProgress $progress): Widget
    {
        $detail = '';
        if ($progress->currentFile !== '') {
            $available = max(0, $area->width - 2);
            $prefix = ' → ';
            $budget = max(0, $available - mb_strlen($prefix));
            $detail = $prefix . Format::truncateName($progress->currentFile, $budget);
        }

        return ParagraphWidget::fromText(Text::fromString($detail))
            ->style(Style::default()->fg(AnsiColor::Gray));
    }
}
