<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;

/**
 * Mirrors vela's src/ui/dialogs.rs cursor_line(): splits the field's text
 * into before/under/after the cursor and renders the "under" character
 * (a space, if the cursor is past the last character) with an inverted
 * style, so it reads as a block cursor without needing real terminal
 * cursor positioning.
 */
final class TextInputRenderer
{
    public static function line(TextInput $input, Style $textStyle, Style $cursorStyle, bool $masked = false): Line
    {
        $value = $masked ? str_repeat('●', mb_strlen($input->value())) : $input->value();
        $cursor = $input->cursor();
        $len = mb_strlen($value);

        $before = mb_substr($value, 0, $cursor);
        $under = $cursor < $len ? mb_substr($value, $cursor, 1) : ' ';
        $after = $cursor < $len ? mb_substr($value, $cursor + 1) : '';

        return Line::fromSpans(
            new Span($before, $textStyle),
            new Span($under, $cursorStyle),
            new Span($after, $textStyle),
        );
    }
}
