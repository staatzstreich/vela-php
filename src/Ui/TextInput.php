<?php

declare(strict_types=1);

namespace Vela\Ui;

/**
 * A single-line editable text field. php-tui has no built-in text input
 * widget (php-tui/php-tui#234), and vela's Rust side reimplements this same
 * insert/backspace/delete/move-cursor state per dialog (RenameDialog,
 * MkdirDialog, PasswordDialog, profile form fields, ...) — here it's one
 * shared class instead, reused by every dialog that needs a field.
 *
 * Cursor position is a character index (not a byte offset): Rust has to
 * manually walk UTF-8 char boundaries for this, but PHP's mb_* functions
 * make that unnecessary.
 */
final class TextInput
{
    private string $value;

    private int $cursor;

    public function __construct(string $initial = '', bool $cursorAtEnd = true)
    {
        $this->value = $initial;
        $this->cursor = $cursorAtEnd ? mb_strlen($initial) : 0;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function setValue(string $value, bool $cursorAtEnd = true): void
    {
        $this->value = $value;
        $this->cursor = $cursorAtEnd ? mb_strlen($value) : min($this->cursor, mb_strlen($value));
    }

    /** Insert text at the cursor and advance past it (handles multi-char paste too). */
    public function insert(string $text): void
    {
        $this->value = mb_substr($this->value, 0, $this->cursor) . $text . mb_substr($this->value, $this->cursor);
        $this->cursor += mb_strlen($text);
    }

    /** Delete the character to the left of the cursor. */
    public function backspace(): void
    {
        if ($this->cursor === 0) {
            return;
        }
        $this->value = mb_substr($this->value, 0, $this->cursor - 1) . mb_substr($this->value, $this->cursor);
        $this->cursor--;
    }

    /** Delete the character to the right of the cursor. */
    public function deleteForward(): void
    {
        if ($this->cursor >= mb_strlen($this->value)) {
            return;
        }
        $this->value = mb_substr($this->value, 0, $this->cursor) . mb_substr($this->value, $this->cursor + 1);
    }

    public function moveLeft(): void
    {
        if ($this->cursor > 0) {
            $this->cursor--;
        }
    }

    public function moveRight(): void
    {
        if ($this->cursor < mb_strlen($this->value)) {
            $this->cursor++;
        }
    }

    public function moveHome(): void
    {
        $this->cursor = 0;
    }

    public function moveEnd(): void
    {
        $this->cursor = mb_strlen($this->value);
    }
}
