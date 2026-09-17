<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
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
use Vela\Theme\Theme;
use Vela\Transfer\TransferProgress;

/**
 * Mirrors vela's src/ui/statusbar.rs render_transfer_bar(): a block-character
 * bar (█ filled / ░ empty) built from styled spans rather than a Gauge
 * widget, with a centered text label overlaid directly onto the bar
 * characters, plus a second row showing the current file.
 */
final class TransferBarRenderer
{
    public static function build(Area $area, TransferProgress $progress, string $verb, Theme $theme): Widget
    {
        $barColor = match ($verb) {
            'Download' => $theme->downloadBar,
            'Copy' => $theme->copyBar,
            default => $theme->uploadBar,
        };

        $rows = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints([Constraint::length(1), Constraint::length(1)])
            ->split($area);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1))
            ->widgets(
                self::buildBar($rows->get(0), $progress, $verb, $barColor, $theme),
                self::buildFilenameRow($rows->get(1), $progress, $theme),
            );
    }

    private static function buildBar(Area $area, TransferProgress $progress, string $verb, AnsiColor $barColor, Theme $theme): Widget
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
            new Span($filledStr, Style::default()->fg($theme->transferFilledFg)->bg($barColor)->addModifier(Modifier::BOLD)),
            new Span($emptyStr, Style::default()->fg($barColor)->bg($theme->transferEmptyBg)),
        );

        return ParagraphWidget::fromText(Text::fromLine($line));
    }

    /**
     * PHP-only addition (no Rust original — vela cancels a background-thread
     * transfer some other way, this port has none): a right-aligned "Esc
     * Abbrechen" hint, in the same badge+label style as the main hint bar
     * (Render::buildHintArea()), reserved out of the filename budget so a
     * long filename never overlaps it.
     */
    private static function buildFilenameRow(Area $area, TransferProgress $progress, Theme $theme): Widget
    {
        $escBadge = new Span(' Esc ', Style::default()->bg($theme->hintBadgeBg)->fg($theme->hintBadgeFg)->addModifier(Modifier::BOLD));
        $escLabel = 'Abbrechen ';
        $hintLen = mb_strlen(' Esc ') + mb_strlen($escLabel);

        $detail = '';
        if ($progress->currentFile !== '') {
            $available = max(0, $area->width - 2 - $hintLen);
            $prefix = ' → ';
            $budget = max(0, $available - mb_strlen($prefix));
            $detail = $prefix . Format::truncateName($progress->currentFile, $budget);
        }

        $padLen = max(0, $area->width - mb_strlen($detail) - $hintLen);

        $line = Line::fromSpans(
            new Span($detail, Style::default()->fg($theme->filenameText)),
            new Span(str_repeat(' ', $padLen), Style::default()->fg($theme->filenameText)),
            $escBadge,
            new Span($escLabel, Style::default()->fg($theme->hintLabel)),
        );

        return ParagraphWidget::fromText(Text::fromLine($line))->style(Style::default()->bg($theme->transferRowBg));
    }
}
