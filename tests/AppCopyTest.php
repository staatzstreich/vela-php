<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers F5/F6 local-to-local copy (App::copyToRight()/copyToLeft(), added
 * this session) end to end through App::handleKey() — the App-level wiring
 * around TransferEngine::copyBatch()/findConflicts(), which are already unit
 * tested directly in tests/Transfer/TransferEngineTest.php. Every App here is
 * disconnected ($sftp === null), which is what routes F5/F6 to the local
 * copy path instead of uploadActive()/downloadActive() (see
 * App::handleMainKey()'s F5/F6 ternary) — the connected/SFTP branch is out
 * of scope, no fake SftpConnection is possible.
 */
final class AppCopyTest extends AppTestCase
{
    #[Test]
    public function copyToRightWithNoConflictCopiesDirectlyWithoutOpeningADialog(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'hello');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertNull($app->copyConflictDialog);
        self::assertSame('hello', file_get_contents("{$this->scratchRight}/a.txt"));
    }

    #[Test]
    public function copyToLeftWithNoConflictCopiesDirectly(): void
    {
        file_put_contents("{$this->scratchRight}/a.txt", 'world');
        $app = $this->makeApp();
        $app->right->selected = self::mustIndexOf($app->right->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(6));

        self::assertNull($app->copyConflictDialog);
        self::assertSame('world', file_get_contents("{$this->scratchLeft}/a.txt"));
    }

    #[Test]
    public function copyWithNoMarksAndNoSelectionIsANoOp(): void
    {
        $app = $this->makeApp();

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertNull($app->copyConflictDialog);
        self::assertNull($app->statusMessage);
    }

    #[Test]
    public function copyOfAMarkedSetCopiesAllOfThemAndClearsMarks(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'a');
        file_put_contents("{$this->scratchLeft}/b.txt", 'b');
        $app = $this->makeApp();
        $aIndex = self::mustIndexOf($app->left->entries, 'a.txt');
        $bIndex = self::mustIndexOf($app->left->entries, 'b.txt');
        $app->left->marked = [$aIndex => true, $bIndex => true];

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertSame('a', file_get_contents("{$this->scratchRight}/a.txt"));
        self::assertSame('b', file_get_contents("{$this->scratchRight}/b.txt"));
        self::assertSame([], $app->left->marked);
    }

    #[Test]
    public function copyOfADirectoryRecursesCorrectly(): void
    {
        mkdir("{$this->scratchLeft}/sub");
        file_put_contents("{$this->scratchLeft}/sub/nested.txt", 'deep');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'sub');

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertSame('deep', file_get_contents("{$this->scratchRight}/sub/nested.txt"));
    }

    #[Test]
    public function conflictOpensCopyConflictDialogInsteadOfCopyingImmediately(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'new');
        file_put_contents("{$this->scratchRight}/a.txt", 'old');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertNotNull($app->copyConflictDialog);
        self::assertSame('old', file_get_contents("{$this->scratchRight}/a.txt"));
    }

    #[Test]
    public function conflictDialogRecordsTheCorrectConflictingNames(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'new');
        file_put_contents("{$this->scratchRight}/a.txt", 'old');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertNotNull($app->copyConflictDialog);
        self::assertSame(['a.txt'], $app->copyConflictDialog->conflictingNames);
    }

    #[Test]
    public function confirmCopyWithYPerformsTheCopyAndClosesDialog(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'new');
        file_put_contents("{$this->scratchRight}/a.txt", 'old');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');
        $app->handleKey(FunctionKeyEvent::new(5));

        $app->handleKey(CharKeyEvent::new('y'));

        self::assertNull($app->copyConflictDialog);
        self::assertSame('new', file_get_contents("{$this->scratchRight}/a.txt"));
    }

    #[Test]
    public function confirmCopyWithEnterAlsoWorks(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'new');
        file_put_contents("{$this->scratchRight}/a.txt", 'old');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');
        $app->handleKey(FunctionKeyEvent::new(5));

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->copyConflictDialog);
        self::assertSame('new', file_get_contents("{$this->scratchRight}/a.txt"));
    }

    #[Test]
    public function cancelCopyWithNClosesDialogWithoutTouchingTheFilesystem(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'new');
        file_put_contents("{$this->scratchRight}/a.txt", 'old');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');
        $app->handleKey(FunctionKeyEvent::new(5));

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertNull($app->copyConflictDialog);
        self::assertSame('old', file_get_contents("{$this->scratchRight}/a.txt"));
    }

    #[Test]
    public function cancelCopyWithEscAlsoWorks(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'new');
        file_put_contents("{$this->scratchRight}/a.txt", 'old');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');
        $app->handleKey(FunctionKeyEvent::new(5));

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->copyConflictDialog);
        self::assertSame('old', file_get_contents("{$this->scratchRight}/a.txt"));
    }

    #[Test]
    public function successfulCopySetsAGermanSuccessStatusMessageAndReloadsDestPanel(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'hello');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertSame('Kopieren abgeschlossen', $app->statusMessage);
        self::assertNotNull(self::indexOf($app->right->entries, 'a.txt'));
    }

    #[Test]
    public function f5AndF6BothRouteThroughRunLocalCopyIdentically(): void
    {
        file_put_contents("{$this->scratchLeft}/left-file.txt", 'from-left');
        file_put_contents("{$this->scratchRight}/right-file.txt", 'from-right');
        $app = $this->makeApp();

        $app->left->selected = self::mustIndexOf($app->left->entries, 'left-file.txt');
        $app->handleKey(FunctionKeyEvent::new(5));
        self::assertSame('from-left', file_get_contents("{$this->scratchRight}/left-file.txt"));

        $app->right->selected = self::mustIndexOf($app->right->entries, 'right-file.txt');
        $app->handleKey(FunctionKeyEvent::new(6));
        self::assertSame('from-right', file_get_contents("{$this->scratchLeft}/right-file.txt"));
    }

    #[Test]
    public function copyResultReloadsOnlyTheDestinationPanelNotTheSource(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');
        // Added after the panel's initial loadLocal() — should stay invisible
        // to $app->left->entries unless something (incorrectly) reloads it.
        file_put_contents("{$this->scratchLeft}/added-after-load.txt", 'x');

        $app->handleKey(FunctionKeyEvent::new(5));

        self::assertNotNull(self::indexOf($app->right->entries, 'a.txt'));
        self::assertNull(self::indexOf($app->left->entries, 'added-after-load.txt'));
    }
}
