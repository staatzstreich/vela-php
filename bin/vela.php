<?php

declare(strict_types=1);

// php-tui/term (0.3.4) still uses implicit-nullable parameter syntax, which
// PHP 8.5 flags as deprecated. Not something we control in vendor code.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\Terminal as TermTerminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use Vela\App;
use Vela\Config\AuthMethod;
use Vela\Config\ProfileStore;
use Vela\Connection\SftpConnection;
use Vela\Terminal\Setup;
use Vela\Ui\CenteredBoxRenderer;
use Vela\Ui\Format;
use Vela\Ui\Render;

date_default_timezone_set(Format::detectLocalTimezone());

/**
 * There's no profile dialog yet (that's milestone 6), so --profile=NAME is
 * a stand-in way to exercise SFTP connect + browse end to end: resolve the
 * named profile, connect *before* entering raw mode (so failures print
 * normally and exit cleanly), then hand the live connection to the App.
 */
function connectFromCliFlag(): ?SftpConnection
{
    $profileName = null;
    foreach ($_SERVER['argv'] as $arg) {
        if (str_starts_with($arg, '--profile=')) {
            $profileName = substr($arg, strlen('--profile='));
        }
    }
    if ($profileName === null) {
        return null;
    }

    $store = ProfileStore::load();
    $profile = null;
    foreach ($store->profiles as $p) {
        if ($p->name === $profileName) {
            $profile = $p;
            break;
        }
    }
    if ($profile === null) {
        fwrite(STDERR, "Unknown profile: {$profileName}\n");
        exit(1);
    }

    $password = null;
    if ($profile->auth === AuthMethod::Password) {
        $password = promptPasswordHidden("Password for {$profile->user}@{$profile->host}: ");
    }

    fwrite(STDERR, "Connecting to {$profile->host}...\n");
    try {
        $sftp = SftpConnection::connect($profile, $password);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    return $sftp;
}

function promptPasswordHidden(string $prompt): string
{
    fwrite(STDERR, $prompt);
    system('stty -echo');
    $password = fgets(STDIN);
    system('stty echo');
    fwrite(STDERR, "\n");

    return $password === false ? '' : rtrim($password, "\n");
}

/**
 * Transfers run synchronously (no portable PHP threading — see
 * Vela\Transfer\TransferEngine's docblock), so this is what keeps the UI
 * from looking frozen: called between chunks, it redraws the progress bar
 * at most every 50ms rather than on every single chunk callback.
 */
function makeTransferTick(\PhpTui\Tui\Display\Display $display, App $app): callable
{
    $last = 0.0;

    return function () use ($display, $app, &$last): void {
        $now = microtime(true);
        if ($now - $last < 0.05) {
            return;
        }
        $last = $now;
        $display->draw(Render::build($app, $display->viewportArea()));
    };
}

function run(TermTerminal $terminal, ?SftpConnection $sftp): void
{
    $backend = PhpTermBackend::new($terminal);
    $display = DisplayBuilder::default($backend)
        ->addWidgetRenderer(new CenteredBoxRenderer())
        ->build();

    $left = getcwd() ?: '/';
    $right = $_SERVER['HOME'] ?? (getenv('HOME') ?: $left);
    $app = new App($left, $right);

    if ($sftp !== null) {
        $app->attachSftp($sftp);
    }

    while ($app->running) {
        $display->draw(Render::build($app, $display->viewportArea()));

        $event = $terminal->events()->next();
        if ($event === null) {
            usleep(50_000);
            continue;
        }

        // Apply every already-buffered event before redrawing again, so a
        // fast paste or fast typing doesn't trigger one full redraw per
        // character (each redraw is cheap in isolation, but a naive
        // one-redraw-per-keystroke loop still makes a paste visibly
        // "trickle in" character by character).
        do {
            if ($event instanceof CharKeyEvent || $event instanceof CodedKeyEvent || $event instanceof FunctionKeyEvent) {
                $app->handleKey($event, makeTransferTick($display, $app));
            }
            $event = $terminal->events()->next();
        } while ($event !== null && $app->running);
    }
}

$sftp = connectFromCliFlag();

$terminal = Setup::setup();
try {
    run($terminal, $sftp);
} finally {
    Setup::restore($terminal);
}
