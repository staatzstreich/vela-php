<?php

declare(strict_types=1);

namespace Vela\Tests;

use Vela\Fs\FileEntry;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\ActivePanel;
use Vela\App;

/**
 * Covers App::handleMainKey() — the key bindings that fire once no dialog is
 * open and help isn't visible: quit, marking, panel navigation, and the
 * guarded entry points (profile/shell/tail) that only need local state to
 * verify their guard clauses. Every App instance here is disconnected
 * ($sftp === null), so navigation always takes the local branch of
 * enterActive()/goUpActive() — the remote branches aren't reachable without
 * a live SftpConnection and are out of scope (see the plan).
 */
final class AppMainKeyTest extends AppTestCase
{
    #[Test]
    public function qQuits(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('q'));

        self::assertFalse($app->running);
    }

    #[Test]
    public function escQuits(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertFalse($app->running);
    }

    #[Test]
    public function f10Quits(): void
    {
        $app = $this->makeApp();

        $app->handleKey(FunctionKeyEvent::new(10));

        self::assertFalse($app->running);
    }

    #[Test]
    public function spaceMarksThenAdvancesSelection(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        file_put_contents("{$this->scratchLeft}/b.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = 1;

        $app->handleKey(CharKeyEvent::new(' '));

        self::assertArrayHasKey(1, $app->left->marked);
        self::assertSame(2, $app->left->selected);
    }

    #[Test]
    public function spaceOnLastEntryMarksButDoesNotAdvancePastTheEnd(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $lastIndex = count($app->left->entries) - 1;
        $app->left->selected = $lastIndex;

        $app->handleKey(CharKeyEvent::new(' '));

        self::assertArrayHasKey($lastIndex, $app->left->marked);
        self::assertSame($lastIndex, $app->left->selected);
    }

    #[Test]
    public function starMarksAllEligibleEntries(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        file_put_contents("{$this->scratchLeft}/b.txt", 'x');
        $app = $this->makeApp();
        $dotDotIndex = self::indexOf($app->left->entries, '..');
        self::assertNotNull($dotDotIndex);

        $app->handleKey(CharKeyEvent::new('*'));

        foreach ($app->left->entries as $i => $entry) {
            if ($entry->name === '..') {
                continue;
            }
            self::assertArrayHasKey($i, $app->left->marked);
        }
        self::assertArrayNotHasKey($dotDotIndex, $app->left->marked);
    }

    #[Test]
    public function starAgainUnmarksAllWhenEverythingWasMarked(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('*'));
        $app->handleKey(CharKeyEvent::new('*'));

        self::assertSame([], $app->left->marked);
    }

    #[Test]
    public function starWithAPartialMarkMarksEverythingRatherThanUnmarking(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        file_put_contents("{$this->scratchLeft}/b.txt", 'x');
        $app = $this->makeApp();
        $aIndex = self::indexOf($app->left->entries, 'a.txt');
        $bIndex = self::indexOf($app->left->entries, 'b.txt');
        self::assertNotNull($aIndex);
        self::assertNotNull($bIndex);
        $app->left->marked = [$aIndex => true];

        $app->handleKey(CharKeyEvent::new('*'));

        self::assertArrayHasKey($aIndex, $app->left->marked);
        self::assertArrayHasKey($bIndex, $app->left->marked);
    }

    #[Test]
    public function tabTogglesActivePanel(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CodedKeyEvent::new(KeyCode::Tab));

        self::assertSame(ActivePanel::Right, $app->active);
    }

    #[Test]
    public function upDownDelegateToPanelStateOnTheActivePanel(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        file_put_contents("{$this->scratchLeft}/b.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = 1;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Down));
        self::assertSame(2, $app->left->selected);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Up));
        self::assertSame(1, $app->left->selected);
    }

    #[Test]
    public function enterOnADirectoryDescendsAndReloads(): void
    {
        mkdir("{$this->scratchLeft}/sub");
        file_put_contents("{$this->scratchLeft}/sub/inner.txt", 'x');
        $app = $this->makeApp();
        $subIndex = self::indexOf($app->left->entries, 'sub');
        self::assertNotNull($subIndex);
        $app->left->selected = $subIndex;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame("{$this->scratchLeft}/sub", $app->left->path);
        self::assertNotNull(self::indexOf($app->left->entries, 'inner.txt'));
    }

    #[Test]
    public function enterOnAPlainFileIsANoOp(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $aIndex = self::indexOf($app->left->entries, 'a.txt');
        self::assertNotNull($aIndex);
        $app->left->selected = $aIndex;
        $pathBefore = $app->left->path;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame($pathBefore, $app->left->path);
    }

    #[Test]
    public function backspaceGoesUpADirectoryLevel(): void
    {
        mkdir("{$this->scratchLeft}/sub");
        $app = new App("{$this->scratchLeft}/sub", $this->scratchRight);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Backspace));

        self::assertSame($this->scratchLeft, $app->left->path);
    }

    #[Test]
    public function backspaceAtFilesystemRootIsANoOp(): void
    {
        $app = new App('/', $this->scratchRight);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Backspace));

        self::assertSame('/', $app->left->path);
    }

    #[Test]
    public function pOpensProfileDialogAgainstTheScratchHomeConfig(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('p'));

        self::assertNotNull($app->profileDialog);
        self::assertSame([], $app->profileDialog->store->profiles);
    }

    #[Test]
    public function bangOpensShellDialog(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('!'));

        self::assertNotNull($app->shellDialog);
    }

    #[Test]
    public function tOnTheLeftPanelSetsANotRemoteStatusMessage(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('t'));

        self::assertSame('Tail nur für Remote-Dateien (rechtes Panel)', $app->statusMessage);
        self::assertNull($app->shellDialog);
    }

    #[Test]
    public function tOnTheRightPanelWhileDisconnectedSetsANotConnectedStatusMessage(): void
    {
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;

        $app->handleKey(CharKeyEvent::new('t'));

        self::assertSame('Nicht verbunden', $app->statusMessage);
        self::assertNull($app->shellDialog);
    }

    /** @param list<FileEntry> $entries */
    private static function indexOf(array $entries, string $name): ?int
    {
        foreach ($entries as $i => $entry) {
            if ($entry->name === $name) {
                return $i;
            }
        }

        return null;
    }
}
