<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\ActivePanel;
use Vela\Dialog\ShellDialog;

/**
 * Covers the shell dialog (App::handleShellDialogKey()/runShellCommand()),
 * both phases: input (typing a command) and output (viewing/scrolling the
 * result). runShellCommand() runs a genuinely real proc_open() against
 * $this->scratchLeft as cwd — no fake/mock needed, unlike anything
 * SFTP-shaped. The tail ('t') guard clauses are already covered in
 * AppMainKeyTest.php (tOnTheLeftPanelSetsANotRemoteStatusMessage()/
 * tOnTheRightPanelWhileDisconnectedSetsANotConnectedStatusMessage()) — not
 * duplicated here.
 */
final class AppShellDialogTest extends AppTestCase
{
    #[Test]
    public function bangAlwaysOpensTheShellDialogRegardlessOfPanel(): void
    {
        $app = $this->makeApp();
        $app->handleKey(CharKeyEvent::new('!'));
        self::assertNotNull($app->shellDialog);

        $app->shellDialog = null;
        $app->active = ActivePanel::Right;
        $app->handleKey(CharKeyEvent::new('!'));
        self::assertNotNull($app->shellDialog);
    }

    #[Test]
    public function typingMovingAndEditingTheCommandInputWorks(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;

        $app->handleKey(CharKeyEvent::new('l'));
        $app->handleKey(CharKeyEvent::new('s'));
        self::assertSame('ls', $dlg->input->value());

        $app->handleKey(CodedKeyEvent::new(KeyCode::Home));
        self::assertSame(0, $dlg->input->cursor());
        $app->handleKey(CodedKeyEvent::new(KeyCode::Delete));
        self::assertSame('s', $dlg->input->value());

        $app->handleKey(CodedKeyEvent::new(KeyCode::End));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Backspace));
        self::assertSame('', $dlg->input->value());
    }

    #[Test]
    public function escInInputPhaseCancelsWithoutRunningAnything(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $app->shellDialog->input->insert('touch marker.txt');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->shellDialog);
        self::assertFileDoesNotExist("{$this->scratchLeft}/marker.txt");
    }

    #[Test]
    public function enterWithAnEmptyCommandClosesTheDialogWithoutRunning(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $app->shellDialog->input->insert('   ');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->shellDialog);
        self::assertNull($app->statusMessage);
    }

    #[Test]
    public function enterRunsARealCommandAgainstTheLeftPanelsCwd(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $app->shellDialog->input->insert('touch marker.txt');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertFileExists("{$this->scratchLeft}/marker.txt");
        self::assertNotNull(self::indexOf($app->left->entries, 'marker.txt'));
    }

    #[Test]
    public function successfulCommandCapturesStdoutIntoOutputLines(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;
        $dlg->input->insert('echo hello');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame($dlg, $app->shellDialog);
        self::assertSame(['hello'], $dlg->output);
        self::assertSame(0, $dlg->exitCode);
    }

    #[Test]
    public function aCommandWithNoOutputShowsThePlaceholderLine(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;
        $dlg->input->insert('true');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame(['(keine Ausgabe)'], $dlg->output);
    }

    #[Test]
    public function aFailingCommandRecordsANonZeroExitCode(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;
        $dlg->input->insert('false');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame(1, $dlg->exitCode);
    }

    #[Test]
    public function statusMessageAfterRunningIncludesTheCommandAndExitCode(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $app->shellDialog->input->insert('true');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame('! true — Exit 0', $app->statusMessage);
    }

    #[Test]
    public function qInOutputPhaseClosesTheDialog(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $app->shellDialog->output = ['line1'];
        $app->shellDialog->exitCode = 0;

        $app->handleKey(CharKeyEvent::new('q'));

        self::assertNull($app->shellDialog);
    }

    #[Test]
    public function escInOutputPhaseAlsoCloses(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $app->shellDialog->output = ['line1'];
        $app->shellDialog->exitCode = 0;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->shellDialog);
    }

    #[Test]
    public function upDownScrollWithFloorAndCeilingClamping(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;
        $dlg->output = array_map(static fn (int $i): string => "line{$i}", range(1, 25));
        $dlg->exitCode = 0;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Up));
        self::assertSame(0, $dlg->scroll);

        for ($i = 0; $i < 10; $i++) {
            $app->handleKey(CodedKeyEvent::new(KeyCode::Down));
        }
        // 25 lines, 20 visible -> max scroll is 5.
        self::assertSame(5, $dlg->scroll);
    }

    #[Test]
    public function pageUpPageDownJumpByThePageSize(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;
        $dlg->output = array_map(static fn (int $i): string => "line{$i}", range(1, 25));
        $dlg->exitCode = 0;

        $app->handleKey(CodedKeyEvent::new(KeyCode::PageDown));
        self::assertSame(5, $dlg->scroll);

        $app->handleKey(CodedKeyEvent::new(KeyCode::PageUp));
        self::assertSame(0, $dlg->scroll);
    }
}
