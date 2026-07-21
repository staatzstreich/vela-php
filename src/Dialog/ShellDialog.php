<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Ui\TextInput;

/** Mirrors vela's src/app.rs ShellDialog. `output === null` means still in the input phase. */
final class ShellDialog
{
    public const VISIBLE_LINES = 20;

    public const PAGE_SIZE = 10;

    public TextInput $input;

    /** @var string[]|null */
    public ?array $output = null;

    public int $scroll = 0;

    public ?int $exitCode = null;

    public function __construct()
    {
        $this->input = new TextInput('');
    }

    public function scrollUp(): void
    {
        $this->scroll = max(0, $this->scroll - 1);
    }

    public function scrollDown(int $totalLines): void
    {
        $max = max(0, $totalLines - self::VISIBLE_LINES);
        if ($this->scroll < $max) {
            $this->scroll++;
        }
    }

    public function pageUp(): void
    {
        $this->scroll = max(0, $this->scroll - self::PAGE_SIZE);
    }

    public function pageDown(int $totalLines): void
    {
        $max = max(0, $totalLines - self::VISIBLE_LINES);
        $this->scroll = min($this->scroll + self::PAGE_SIZE, $max);
    }
}
