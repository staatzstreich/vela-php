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
use Vela\Config\AuthMethod;
use Vela\Config\ProfileStore;
use Vela\Connection\SftpConnection;
use Vela\Terminal\Setup;
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

function run(TermTerminal $terminal, ?SftpConnection $sftp): void
{
    $backend = PhpTermBackend::new($terminal);
    $display = DisplayBuilder::default($backend)->build();

    $left = getcwd() ?: '/';
    $right = $_SERVER['HOME'] ?? (getenv('HOME') ?: $left);
    $app = new App($left, $right);

    if ($sftp !== null) {
        $app->attachSftp($sftp);
    }

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

$sftp = connectFromCliFlag();

$terminal = Setup::setup();
try {
    run($terminal, $sftp);
} finally {
    Setup::restore($terminal);
}
