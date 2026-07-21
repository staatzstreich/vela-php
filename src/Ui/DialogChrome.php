<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

/** Mirrors vela's src/ui/dialogs.rs hint_key()/hint_label() badge-style hint row. */
final class DialogChrome
{
    /** @param array<string,string> $pairs key label => description */
    public static function hints(array $pairs): Line
    {
        $spans = [];
        foreach ($pairs as $key => $label) {
            $spans[] = new Span(
                " {$key} ",
                Style::default()->bg(AnsiColor::DarkGray)->fg(AnsiColor::White)->addModifier(Modifier::BOLD),
            );
            $spans[] = new Span("  {$label}  ", Style::default()->fg(AnsiColor::Gray));
        }

        return Line::fromSpans(...$spans);
    }
}
