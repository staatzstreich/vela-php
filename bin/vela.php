<?php

declare(strict_types=1);

use PhpTui\Tui\Display\Display;

// php-tui/term (0.3.4) still uses implicit-nullable parameter syntax, which
// PHP 8.5 flags as deprecated. Not something we control in vendor code.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal as TermTerminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\ImageMagick\ImageMagickExtension;
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
    global $argv;

    $profileName = null;
    foreach ($argv as $arg) {
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
 * Find an editor binary that is actually installed: $EDITOR, $VISUAL, then
 * vim/nano/vi, each verified with `command -v` (mirrors main.rs find_editor;
 * the value may carry flags like "code --wait", so only the first token is
 * checked for existence).
 */
function findEditor(): ?string
{
    $candidates = array_filter([
        getenv('EDITOR') ?: null,
        getenv('VISUAL') ?: null,
        'vim',
        'nano',
        'vi',
    ], static fn (?string $c): bool => $c !== null && trim($c) !== '');

    foreach ($candidates as $candidate) {
        $binary = explode(' ', trim($candidate))[0];
        $found = shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if (is_string($found) && trim($found) !== '') {
            return $candidate;
        }
    }

    return null;
}

/**
 * Suspend the TUI, run the editor on the file, restore the TUI. The editor
 * exit code is ignored — finishEdit()'s mtime comparison decides whether
 * anything was saved. Mirrors main.rs launch_editor().
 *
 * Deliberately proc_open() with explicit STDIN/STDOUT/STDERR descriptors
 * rather than system(): once anything in the process has called
 * stream_set_blocking() (php-tui/term's SyncTtyEventProvider does, for
 * non-blocking input polling — and, confirmed by testing, so does calling
 * it on *any* stream at all, not just STDIN), system()'s own descriptor
 * inheritance breaks in a way that makes the child editor think its output
 * isn't a terminal (e.g. vim prints "Warning: Output is not to a
 * terminal"). proc_open() with an explicit descriptor array reliably
 * doesn't have this problem.
 */
function launchEditor(TermTerminal $terminal, Display $display, string $path): void
{
    $editor = findEditor();
    if ($editor === null) {
        return;
    }

    Setup::restore($terminal);
    $process = proc_open($editor . ' ' . escapeshellarg($path), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }
    Setup::resume($terminal);
    $display->clear();
}

/**
 * Transfers run synchronously (no portable PHP threading — see
 * Vela\Transfer\TransferEngine's docblock), so this is what keeps the UI
 * from looking frozen: called between chunks, it redraws the progress bar
 * at most every 50ms rather than on every single chunk callback. At that
 * same cadence it also polls the terminal for a pending Esc keypress — the
 * only way input reaches the app while a transfer blocks the main loop —
 * and marks TransferProgress::$cancelled, which TransferEngine::tick()
 * turns into a clean unwind (see TransferCancelledException). Any other key
 * pressed during a transfer is drained and dropped here rather than queued
 * for after the transfer.
 */
function makeTransferTick(TermTerminal $terminal, Display $display, App $app): callable
{
    $last = 0.0;

    return function () use ($terminal, $display, $app, &$last): void {
        $now = microtime(true);
        if ($now - $last < 0.05) {
            return;
        }
        $last = $now;

        $event = $terminal->events()->next();
        if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc && $app->activeTransfer !== null) {
            $app->activeTransfer->cancelled = true;
        }

        $display->draw(Render::build($app, $display->viewportArea()));
    };
}

function run_vela(TermTerminal $terminal, ?SftpConnection $sftp): void
{
    $backend = PhpTermBackend::new($terminal);
    $display = DisplayBuilder::default($backend)
        ->addWidgetRenderer(new CenteredBoxRenderer())
        ->addExtension(new ImageMagickExtension())
        ->build();

    $left = getcwd() ?: '/';
    $home = $_SERVER['HOME'] ?? getenv('HOME');
    $right = is_string($home) && $home !== '' ? $home : $left;
    $app = new App($left, $right);

    if ($sftp !== null) {
        $app->attachSftp($sftp);
    }

    while ($app->running) {
        $viewport = $display->viewportArea();
        $app->viewportCols = $viewport->width;
        $app->viewportRows = $viewport->height;
        $display->draw(Render::build($app, $viewport));

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
                $app->handleKey($event, makeTransferTick($terminal, $display, $app));
            }
            $event = $terminal->events()->next();
        } while ($event !== null && $app->running);

        // F4: editor handoff — suspend the TUI, run $EDITOR, restore, then
        // let finishEdit() decide whether a re-upload is needed (mirrors
        // the pending_edit block in main.rs's run()).
        if ($app->pendingEdit !== null) {
            $req = $app->pendingEdit;
            $app->pendingEdit = null;
            launchEditor($terminal, $display, $req->editPath);
            $app->finishEdit($req);
        }
    }
}

$sftp = connectFromCliFlag();

$terminal = Setup::setup();
try {
    run_vela($terminal, $sftp);
} finally {
    Setup::restore($terminal);
}
