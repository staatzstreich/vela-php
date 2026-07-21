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
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use Vela\Terminal\Setup;
use Vela\Ui\TextInput;
use Vela\Ui\TextInputRenderer;

/**
 * Standalone spike for the TextInput widget (milestone 4) — no dialog
 * system exists yet (that's milestone 6), so this proves out typing,
 * cursor movement, and masked rendering interactively before either lands.
 */
function run(TermTerminal $terminal): void
{
    $backend = PhpTermBackend::new($terminal);
    $display = DisplayBuilder::default($backend)->build();

    $input = new TextInput('README.md');
    $masked = false;
    $running = true;

    while ($running) {
        $textStyle = Style::default()->fg(AnsiColor::White);
        $cursorStyle = Style::default()->bg(AnsiColor::White)->fg(AnsiColor::Black)->addModifier(Modifier::BOLD);
        $line = TextInputRenderer::line($input, $textStyle, $cursorStyle, $masked);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' TextInput demo (Tab: toggle mask, Esc: quit) '))
            ->widget(ParagraphWidget::fromText(Text::fromLines(
                $line,
                \PhpTui\Tui\Text\Line::fromString(''),
                \PhpTui\Tui\Text\Line::fromString(sprintf(
                    'value=%s cursor=%d masked=%s',
                    $input->value(),
                    $input->cursor(),
                    $masked ? 'yes' : 'no',
                )),
            )));

        $display->draw($block);

        $event = $terminal->events()->next();

        if ($event instanceof CharKeyEvent) {
            $input->insert($event->char);
        } elseif ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Left => $input->moveLeft(),
                KeyCode::Right => $input->moveRight(),
                KeyCode::Home => $input->moveHome(),
                KeyCode::End => $input->moveEnd(),
                KeyCode::Backspace => $input->backspace(),
                KeyCode::Delete => $input->deleteForward(),
                KeyCode::Tab => $masked = !$masked,
                KeyCode::Esc => $running = false,
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
