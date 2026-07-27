<?php

declare(strict_types=1);

namespace Vela\Terminal;

use PhpTui\Term\Actions;
use PhpTui\Term\RawMode\WindowsRawMode;
use PhpTui\Term\Terminal as TermTerminal;

/**
 * Mirrors vela's src/main.rs setup_terminal()/restore_terminal() split.
 */
final class Setup
{
    public static function setup(): TermTerminal
    {
        // SttyRawMode (Terminal::new()'s default) shells out to `stty`,
        // which doesn't exist on Windows — WindowsRawMode (patched in, see
        // patches/README.md) uses the Win32 Console API via FFI instead.
        $rawMode = PHP_OS_FAMILY === 'Windows' ? WindowsRawMode::new() : null;
        $terminal = TermTerminal::new(rawMode: $rawMode);
        $terminal->enableRawMode();
        $terminal->execute(Actions::cursorHide(), Actions::alternateScreenEnable());

        return $terminal;
    }

    public static function restore(TermTerminal $terminal): void
    {
        $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
        $terminal->disableRawMode();
    }

    /** Re-enter the TUI on an existing terminal after a restore() (editor handoff). */
    public static function resume(TermTerminal $terminal): void
    {
        $terminal->enableRawMode();
        $terminal->execute(Actions::cursorHide(), Actions::alternateScreenEnable());
    }
}
