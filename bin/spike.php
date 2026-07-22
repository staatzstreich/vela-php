<?php

declare(strict_types=1);

// php-tui/term (0.3.4) still uses implicit-nullable parameter syntax, which
// PHP 8.5 flags as deprecated. Not something we control in vendor code.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal as TermTerminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Borders;

/**
 * Feasibility spike: does php-tui/term's raw mode + rendering + key input
 * work reliably in a real macOS terminal? Shaped after vela's own
 * setup_terminal()/restore_terminal()/run() split in src/main.rs so it can
 * be promoted into src/Terminal/... mechanically if this proves out.
 */
function setup_terminal(): TermTerminal
{
    $terminal = TermTerminal::new();
    $terminal->enableRawMode();
    $terminal->execute(Actions::cursorHide(), Actions::alternateScreenEnable());

    return $terminal;
}

function restore_terminal(TermTerminal $terminal): void
{
    $terminal->execute(Actions::cursorShow(), Actions::alternateScreenDisable());
    $terminal->disableRawMode();
}

function run_spike(TermTerminal $terminal): void
{
    $backend = PhpTermBackend::new($terminal);
    $display = DisplayBuilder::default($backend)->build();

    $running = true;
    $frame = 0;

    while ($running) {
        $frame++;

        $display->draw(
            BlockWidget::default()
                ->borders(Borders::ALL)
                ->titles(Title::fromString('vela-php spike'))
                ->borderType(BorderType::Rounded)
                ->widget(ParagraphWidget::fromString(
                    "Raw mode: ON | Press q to quit | Resize me\nframe: {$frame}"
                ))
        );

        $event = $terminal->events()->next();

        if ($event instanceof CharKeyEvent && $event->char === 'q') {
            $running = false;
        } elseif ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
            $running = false;
        } elseif ($event === null) {
            usleep(50_000);
        }
    }
}

$terminal = setup_terminal();
try {
    run_spike($terminal);
} finally {
    restore_terminal($terminal);
}
