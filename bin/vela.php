<?php

declare(strict_types=1);

// php-tui/term (0.3.4) still uses implicit-nullable parameter syntax, which
// PHP 8.5 flags as deprecated. Not something we control in vendor code.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal as TermTerminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use Vela\App;
use Vela\Terminal\Setup;
use Vela\Ui\Format;
use Vela\Ui\Render;

date_default_timezone_set(Format::detectLocalTimezone());

function run(TermTerminal $terminal): void
{
    $backend = PhpTermBackend::new($terminal);
    $display = DisplayBuilder::default($backend)->build();

    $left = getcwd() ?: '/';
    $right = $_SERVER['HOME'] ?? (getenv('HOME') ?: $left);
    $app = new App($left, $right);

    while ($app->running) {
        $display->draw(Render::build($app, $display->viewportArea()));

        $event = $terminal->events()->next();

        if ($event instanceof CharKeyEvent) {
            if ($event->char === 'q') {
                $app->quit();
            } elseif ($event->char === ' ') {
                $app->activePanel()->toggleMark();
                $app->activePanel()->moveDown();
            } elseif ($event->char === '*') {
                $app->activePanel()->markAll();
            }
        } elseif ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Tab => $app->togglePanel(),
                KeyCode::Up => $app->activePanel()->moveUp(),
                KeyCode::Down => $app->activePanel()->moveDown(),
                KeyCode::Enter => $app->enterActive(),
                KeyCode::Backspace => $app->goUpActive(),
                KeyCode::Esc => $app->quit(),
                default => null,
            };
        } elseif ($event === null) {
            usleep(50_000);
        }
    }
}

$terminal = Setup::setup();
try {
    run($terminal);
} finally {
    Setup::restore($terminal);
}
