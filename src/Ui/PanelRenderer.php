<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use Vela\Fs\PanelState;
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/panels.rs render_panel(). */
final class PanelRenderer
{
    private const COL_PADDING = 2;

    public static function renderPanel(
        Area $area,
        PanelState $panel,
        bool $isActive,
        string $label,
        Theme $theme,
        bool $showPermissions = false,
    ): BlockWidget {
        $borderStyle = Style::default()->fg($isActive ? $theme->panelActiveBorder : $theme->panelInactiveBorder);

        // php-tui doesn't clip overlong titles to the block's own width the
        // way ratatui does — an untruncated title can overwrite the
        // top-right corner. Truncate ourselves to the border line's width.
        $title = Format::truncateName(" {$label} — {$panel->path} ", max(0, $area->width - 2));

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString($title))
            ->borderStyle($borderStyle);

        $inner = $block->inner($area);

        // 1 (mark) + 2 (icon) + padding*2 (two "  " separators) + size + date + 2 (highlight symbol)
        // + padding + perm (only when show_permissions, mirrors the remote panel)
        $permCols = $showPermissions ? self::COL_PADDING + Format::COL_PERM : 0;
        $fixedCols = 1 + 2 + self::COL_PADDING * 2 + Format::COL_SIZE + Format::COL_DATE + 2 + $permCols;
        $nameWidth = max(0, $inner->width - $fixedCols);

        $items = [];
        foreach ($panel->entries as $idx => $entry) {
            $isMarked = isset($panel->marked[$idx]);

            $baseStyle = $entry->isDir
                ? Style::default()->fg($theme->directoryIcon)->addModifier(Modifier::BOLD)
                : Style::default()->fg($theme->fileName);
            $icon = $entry->isDir ? '▶ ' : '  ';

            $nameStyle = $isMarked
                ? Style::default()->fg($theme->markedEntry)->addModifier(Modifier::BOLD)
                : $baseStyle;

            $sizeStr = $entry->size !== null
                ? Format::size($entry->size)
                : sprintf('%' . Format::COL_SIZE . 's', '');
            $dateStr = $entry->modifiedAt !== null
                ? Format::date($entry->modifiedAt)
                : sprintf('%' . Format::COL_DATE . 's', '');

            $spans = [
                new Span($isMarked ? '✓' : ' ', Style::default()->fg($theme->markIndicator)->addModifier(Modifier::BOLD)),
                new Span($icon, $baseStyle),
                new Span(Format::padRight(Format::truncateName($entry->name, $nameWidth), $nameWidth), $nameStyle),
                Span::fromString('  '),
                new Span($sizeStr, Style::default()->fg($theme->sizeText)),
                Span::fromString('  '),
                new Span($dateStr, Style::default()->fg($theme->dateText)),
            ];

            if ($showPermissions) {
                $permStr = sprintf('  %' . Format::COL_PERM . 's', $entry->permissions ?? '');
                $spans[] = new Span($permStr, Style::default()->fg($theme->permissionText));
            }

            $items[] = ListItem::new(Text::fromLine(Line::fromSpans(...$spans)));
        }

        $list = ListWidget::default()
            ->items(...$items)
            ->select($panel->selected)
            ->highlightStyle(Style::default()->bg($theme->highlightBg)->fg($theme->highlightFg)->addModifier(Modifier::BOLD))
            ->highlightSymbol(Theme::HIGHLIGHT_SYMBOL);

        return $block->widget($list);
    }
}
