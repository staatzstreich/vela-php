<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\ActivePanel;
use Vela\Dialog\DeleteDialog;
use Vela\Dialog\MkdirDialog;
use Vela\Dialog\PanelSide;
use Vela\Dialog\RenameDialog;

/**
 * Covers Rename (F2)/Mkdir (F7)/Delete (F8) — local-side branches only. Every
 * App here is disconnected ($sftp === null); the right panel is a real local
 * listing too (Milestone A restored that), but openRenameDialog()/
 * openMkdirDialog()/openDeleteDialog() all guard "right panel + disconnected"
 * as a no-op regardless — verified explicitly below, since it's a real,
 * slightly non-obvious behavior (the right panel LOOKS browsable, but these
 * three actions only ever operate on it once actually connected). The
 * remote-side branches of confirmRename()/confirmMkdir()/confirmDelete() are
 * out of scope — no fake SftpConnection is possible.
 *
 * phpunit.xml has failOnWarning="true", so failure-path tests can't rely on a
 * native rename()/mkdir()/unlink() warning — they use an embedded NUL byte,
 * which PHP 8 rejects with a thrown ValueError instead of a warning
 * (confirmed directly with `php -r` during planning).
 */
final class AppFileOpsTest extends AppTestCase
{
    // -------------------------------------------------------------------
    // Rename (F2)
    // -------------------------------------------------------------------

    #[Test]
    public function openRenameDialogOnTheLeftPanelPrefillsTheOriginalName(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(2));

        self::assertNotNull($app->renameDialog);
        self::assertSame(PanelSide::Left, $app->renameDialog->side);
        self::assertSame('a.txt', $app->renameDialog->input->value());
    }

    #[Test]
    public function openRenameDialogOnDotDotIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, '..');

        $app->handleKey(FunctionKeyEvent::new(2));

        self::assertNull($app->renameDialog);
    }

    #[Test]
    public function openRenameDialogOnTheRightPanelWhileDisconnectedIsAlwaysANoOpEvenThoughTheRightPanelShowsRealLocalFiles(): void
    {
        file_put_contents("{$this->scratchRight}/a.txt", 'x');
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;
        $app->right->selected = self::mustIndexOf($app->right->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(2));

        self::assertNull($app->renameDialog);
    }

    #[Test]
    public function typingCharsInsertsIntoTheRenameInput(): void
    {
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'a.txt');

        $app->handleKey(CharKeyEvent::new('x'));

        self::assertNotNull($app->renameDialog);
        self::assertSame('a.txtx', $app->renameDialog->input->value());
    }

    #[Test]
    public function arrowsHomeEndMoveTheCursorInRenameInput(): void
    {
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'abc');
        $dlg = $app->renameDialog;
        self::assertSame(3, $dlg->input->cursor());

        $app->handleKey(CodedKeyEvent::new(KeyCode::Home));
        self::assertSame(0, $dlg->input->cursor());

        $app->handleKey(CodedKeyEvent::new(KeyCode::Right));
        self::assertSame(1, $dlg->input->cursor());

        $app->handleKey(CodedKeyEvent::new(KeyCode::End));
        self::assertSame(3, $dlg->input->cursor());

        $app->handleKey(CodedKeyEvent::new(KeyCode::Left));
        self::assertSame(2, $dlg->input->cursor());
    }

    #[Test]
    public function backspaceAndDeleteEditRenameInput(): void
    {
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'abc');
        $dlg = $app->renameDialog;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Backspace));
        self::assertSame('ab', $dlg->input->value());

        $dlg->input->moveHome();
        $app->handleKey(CodedKeyEvent::new(KeyCode::Delete));
        self::assertSame('b', $dlg->input->value());
    }

    #[Test]
    public function escCancelsRenameWithoutTouchingTheFilesystem(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'a.txt');
        $app->renameDialog->input->insert('-renamed');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->renameDialog);
        self::assertFileExists("{$this->scratchLeft}/a.txt");
    }

    #[Test]
    public function confirmRenameWithAnEmptyTrimmedNameIsANoOp(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'a.txt');
        $app->renameDialog->input->setValue('   ');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->renameDialog);
        self::assertFileExists("{$this->scratchLeft}/a.txt");
        self::assertNull($app->statusMessage);
    }

    #[Test]
    public function confirmRenameWithTheUnchangedOriginalNameIsANoOp(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'a.txt');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->renameDialog);
        self::assertNull($app->statusMessage);
    }

    #[Test]
    public function confirmRenameActuallyRenamesAndReloadsTheLeftPanel(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'content');
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'a.txt');
        $app->renameDialog->input->setValue('b.txt');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->renameDialog);
        self::assertFileDoesNotExist("{$this->scratchLeft}/a.txt");
        self::assertSame('content', file_get_contents("{$this->scratchLeft}/b.txt"));
        self::assertSame('Umbenannt: a.txt → b.txt', $app->statusMessage);
        self::assertNotNull(self::indexOf($app->left->entries, 'b.txt'));
    }

    #[Test]
    public function confirmRenameFailureSetsAnErrorStatusMessageWithoutCrashing(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'a.txt');
        $app->renameDialog->input->setValue("bad\0name");

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->renameDialog);
        self::assertNotNull($app->statusMessage);
        self::assertStringStartsWith('Umbenennen fehlgeschlagen', $app->statusMessage);
        self::assertFileExists("{$this->scratchLeft}/a.txt");
    }

    // -------------------------------------------------------------------
    // Mkdir (F7)
    // -------------------------------------------------------------------

    #[Test]
    public function openMkdirDialogOnTheLeftPanelOpens(): void
    {
        $app = $this->makeApp();

        $app->handleKey(FunctionKeyEvent::new(7));

        self::assertNotNull($app->mkdirDialog);
        self::assertSame(PanelSide::Left, $app->mkdirDialog->side);
    }

    #[Test]
    public function openMkdirDialogOnTheRightPanelWhileDisconnectedIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;

        $app->handleKey(FunctionKeyEvent::new(7));

        self::assertNull($app->mkdirDialog);
    }

    #[Test]
    public function confirmMkdirWithAnEmptyNameIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->mkdirDialog = new MkdirDialog(PanelSide::Left);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->mkdirDialog);
        self::assertNull($app->statusMessage);
    }

    #[Test]
    public function confirmMkdirCreatesARealDirectoryAndReloads(): void
    {
        $app = $this->makeApp();
        $app->mkdirDialog = new MkdirDialog(PanelSide::Left);
        $app->mkdirDialog->input->insert('newdir');

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->mkdirDialog);
        self::assertDirectoryExists("{$this->scratchLeft}/newdir");
        self::assertSame('Verzeichnis erstellt: newdir', $app->statusMessage);
        self::assertNotNull(self::indexOf($app->left->entries, 'newdir'));
    }

    #[Test]
    public function confirmMkdirFailureSetsAnErrorStatusMessage(): void
    {
        $app = $this->makeApp();
        $app->mkdirDialog = new MkdirDialog(PanelSide::Left);
        $app->mkdirDialog->input->setValue("bad\0name");

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->mkdirDialog);
        self::assertNotNull($app->statusMessage);
        self::assertStringStartsWith('Erstellen fehlgeschlagen', $app->statusMessage);
    }

    // -------------------------------------------------------------------
    // Delete (F8)
    // -------------------------------------------------------------------

    #[Test]
    public function openDeleteDialogWithNothingSelectableIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, '..');

        $app->handleKey(FunctionKeyEvent::new(8));

        self::assertNull($app->deleteDialog);
    }

    #[Test]
    public function openDeleteDialogOnTheRightPanelWhileDisconnectedIsANoOp(): void
    {
        file_put_contents("{$this->scratchRight}/a.txt", 'x');
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;
        $app->right->selected = self::mustIndexOf($app->right->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(8));

        self::assertNull($app->deleteDialog);
    }

    #[Test]
    public function cancelDeleteWithNLeavesTheFilesystemUntouched(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [['name' => 'a.txt', 'isDir' => false]]);

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertNull($app->deleteDialog);
        self::assertFileExists("{$this->scratchLeft}/a.txt");
    }

    #[Test]
    public function confirmDeleteOfASingleFileUsesTheSingularStatusMessageWording(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [['name' => 'a.txt', 'isDir' => false]]);

        $app->handleKey(CharKeyEvent::new('y'));

        self::assertFileDoesNotExist("{$this->scratchLeft}/a.txt");
        self::assertSame("'a.txt' gelöscht", $app->statusMessage);
    }

    #[Test]
    public function confirmDeleteOfMultipleMarkedEntriesUsesThePluralWording(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        file_put_contents("{$this->scratchLeft}/b.txt", 'x');
        $app = $this->makeApp();
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [
            ['name' => 'a.txt', 'isDir' => false],
            ['name' => 'b.txt', 'isDir' => false],
        ]);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertFileDoesNotExist("{$this->scratchLeft}/a.txt");
        self::assertFileDoesNotExist("{$this->scratchLeft}/b.txt");
        self::assertSame('2 Einträge gelöscht', $app->statusMessage);
    }

    #[Test]
    public function confirmDeleteRecursivelyRemovesADirectoryAndItsContents(): void
    {
        mkdir("{$this->scratchLeft}/sub");
        file_put_contents("{$this->scratchLeft}/sub/nested.txt", 'x');
        $app = $this->makeApp();
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [['name' => 'sub', 'isDir' => true]]);

        $app->handleKey(CharKeyEvent::new('y'));

        self::assertDirectoryDoesNotExist("{$this->scratchLeft}/sub");
    }

    #[Test]
    public function confirmDeleteOfAMixOfFilesAndDirectoriesInOneBatch(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        mkdir("{$this->scratchLeft}/sub");
        file_put_contents("{$this->scratchLeft}/sub/nested.txt", 'x');
        $app = $this->makeApp();
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [
            ['name' => 'a.txt', 'isDir' => false],
            ['name' => 'sub', 'isDir' => true],
        ]);

        $app->handleKey(CharKeyEvent::new('y'));

        self::assertFileDoesNotExist("{$this->scratchLeft}/a.txt");
        self::assertDirectoryDoesNotExist("{$this->scratchLeft}/sub");
    }

    #[Test]
    public function confirmDeleteClearsMarksAndReloadsTheLeftPanelAfterward(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $aIndex = self::mustIndexOf($app->left->entries, 'a.txt');
        $app->left->marked = [$aIndex => true];
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [['name' => 'a.txt', 'isDir' => false]]);

        $app->handleKey(CharKeyEvent::new('y'));

        self::assertSame([], $app->left->marked);
        self::assertNull(self::indexOf($app->left->entries, 'a.txt'));
    }
}
