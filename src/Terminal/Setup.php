<?php

declare(strict_types=1);

namespace Vela\Terminal;

use PhpTui\Term\Actions;
use PhpTui\Term\Terminal as TermTerminal;

/**
 * Mirrors vela's src/main.rs setup_terminal()/restore_terminal() split.
 */
final class Setup
{
    public static function setup(): TermTerminal
    {
        $terminal = TermTerminal::new();
        $terminal->enableRawMode();
        $terminal->execute(Actions::cursorHide(), Actions::alternateScreenEnable());

        return $terminal;
    }

    public static function restore(TermTerminal $terminal): void
    {
        $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
        $terminal->disableRawMode();
    }
}
