<?php

declare(strict_types=1);

namespace Vela\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Ui\TextInput;

/**
 * Converts milestone 4's ad-hoc scratch verification into a checked-in
 * test — including the unicode/emoji cases, since cursor-position bugs are
 * exactly the kind of thing that "works for plain ASCII, breaks on emoji"
 * and only a real test (not eyeballing) reliably catches.
 */
final class TextInputTest extends TestCase
{
    #[Test]
    public function insertAppendsAndAdvancesCursor(): void
    {
        $input = new TextInput();

        $input->insert('h');
        $input->insert('i');

        self::assertSame('hi', $input->value());
        self::assertSame(2, $input->cursor());
    }

    #[Test]
    public function backspaceRemovesCharacterBeforeCursor(): void
    {
        $input = new TextInput('hi');

        $input->backspace();

        self::assertSame('h', $input->value());
        self::assertSame(1, $input->cursor());
    }

    #[Test]
    public function backspaceAtPositionZeroIsANoOp(): void
    {
        $input = new TextInput('x', cursorAtEnd: false);

        $input->backspace();

        self::assertSame('x', $input->value());
        self::assertSame(0, $input->cursor());
    }

    #[Test]
    public function cursorStartsAtEndOfInitialValueByDefault(): void
    {
        $input = new TextInput('README.md');

        self::assertSame(9, $input->cursor());
    }

    #[Test]
    public function moveHomeThenInsertPrependsText(): void
    {
        $input = new TextInput('README.md');

        $input->moveHome();
        $input->insert('X');

        self::assertSame('XREADME.md', $input->value());
    }

    #[Test]
    public function deleteForwardAtStartRemovesFirstCharacter(): void
    {
        $input = new TextInput('ab');
        $input->moveHome();

        $input->deleteForward();

        self::assertSame('b', $input->value());
    }

    #[Test]
    public function deleteForwardAtEndIsANoOp(): void
    {
        $input = new TextInput('ab'); // cursor defaults to end

        $input->deleteForward();

        self::assertSame('ab', $input->value());
    }

    #[Test]
    public function moveRightClampsAtEnd(): void
    {
        $input = new TextInput('b'); // cursor at end (1) already

        $input->moveRight();
        $input->moveRight();

        self::assertSame(1, $input->cursor());
    }

    #[Test]
    public function moveLeftClampsAtStart(): void
    {
        $input = new TextInput('b', cursorAtEnd: false);

        $input->moveLeft();

        self::assertSame(0, $input->cursor());
    }

    #[Test]
    public function setValueDefaultsCursorToEnd(): void
    {
        $input = new TextInput('short');

        $input->setValue('a much longer value');

        self::assertSame(mb_strlen('a much longer value'), $input->cursor());
    }

    #[Test]
    public function setValueClampsCursorWhenNewValueIsShorter(): void
    {
        // Cursor is at position 5 (end of "hello"); replacing with a
        // 2-character value must pull the cursor back in-bounds rather
        // than leaving it pointing past the end of the string.
        $input = new TextInput('hello');

        $input->setValue('ab', cursorAtEnd: false);

        self::assertSame(2, $input->cursor());
    }

    /**
     * Insert a multi-byte character mid-string and confirm the surrounding
     * text is untouched — the classic bug this would catch is treating the
     * cursor as a byte offset instead of a character index, which corrupts
     * neighboring multi-byte characters.
     */
    #[Test]
    #[DataProvider('multiByteCharacterProvider')]
    public function insertHandlesMultiByteCharactersCorrectly(string $char): void
    {
        $input = new TextInput('äöü');
        $input->moveLeft(); // cursor between ö and ü

        $input->insert($char);

        self::assertSame("äö{$char}ü", $input->value());
        self::assertSame(4, mb_strlen($input->value()));
    }

    /** @return array<string,array{string}> */
    public static function multiByteCharacterProvider(): array
    {
        return [
            'umlaut' => ['ß'],
            'emoji' => ['🎉'],
            'cjk' => ['中'],
        ];
    }

    #[Test]
    public function backspaceRemovesExactlyOneMultiByteCharacterNotOneByte(): void
    {
        $input = new TextInput('äö🎉ü');
        $input->moveLeft(); // cursor between 🎉 and ü

        $input->backspace();

        self::assertSame('äöü', $input->value());
    }
}
