<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PHPUnit\Framework\Attributes\Test;
use Vela\Config\AuthMethod;
use Vela\Config\Profile;
use Vela\Config\ProfileStore;
use Vela\Dialog\CopyConflictDialog;
use Vela\Dialog\DeleteDialog;
use Vela\Dialog\HostKeyDialog;
use Vela\Dialog\MkdirDialog;
use Vela\Dialog\PanelSide;
use Vela\Dialog\PasswordDialog;
use Vela\Dialog\PermissionFixDialog;
use Vela\Dialog\ProfileDialog;
use Vela\Dialog\RenameDialog;
use Vela\Dialog\ShellDialog;
use Vela\Fs\FileEntry;

/**
 * Covers App::handleKey()'s own routing, independent of any single dialog's
 * internal behavior: the F1 help toggle, help-mode input swallowing, the
 * Ctrl+U/S/T shortcuts that work regardless of dialog state, and the fixed
 * dialog-priority chain (only one dialog's handler ever runs per key, in a
 * specific, load-bearing order). Dialog DTOs are constructed directly and
 * assigned to App's public properties rather than opened via their guarded
 * open*Dialog() methods — this is not a shortcut around real behavior, it's
 * exactly how handleKey() itself reads them, and lets these tests stay
 * entirely SFTP-free.
 */
final class AppDispatchTest extends AppTestCase
{
    #[Test]
    public function f1TogglesHelpVisibleOn(): void
    {
        $app = $this->makeApp();

        $app->handleKey(FunctionKeyEvent::new(1));

        self::assertTrue($app->helpVisible);
    }

    #[Test]
    public function f1TogglesHelpVisibleOffAgain(): void
    {
        $app = $this->makeApp();

        $app->handleKey(FunctionKeyEvent::new(1));
        $app->handleKey(FunctionKeyEvent::new(1));

        self::assertFalse($app->helpVisible);
    }

    #[Test]
    public function whileHelpIsOpenEscClosesIt(): void
    {
        $app = $this->makeApp();
        $app->helpVisible = true;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertFalse($app->helpVisible);
    }

    #[Test]
    public function whileHelpIsOpenQClosesItToo(): void
    {
        $app = $this->makeApp();
        $app->helpVisible = true;

        $app->handleKey(CharKeyEvent::new('q'));

        self::assertFalse($app->helpVisible);
        self::assertTrue($app->running);
    }

    #[Test]
    public function whileHelpIsOpenAnyOtherKeyIsSwallowed(): void
    {
        $app = $this->makeApp();
        $app->helpVisible = true;

        $app->handleKey(CharKeyEvent::new(' '));

        self::assertTrue($app->helpVisible);
        self::assertSame([], $app->left->marked);
    }

    #[Test]
    public function ctrlUTogglesPanelsSwapped(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('u', KeyModifiers::CONTROL));

        self::assertTrue($app->panelsSwapped);
    }

    #[Test]
    public function ctrlSAlsoTogglesPanelsSwapped(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('s', KeyModifiers::CONTROL));

        self::assertTrue($app->panelsSwapped);
    }

    #[Test]
    public function ctrlTCyclesThemeEvenWhileHelpIsClosed(): void
    {
        $app = $this->makeApp();
        $before = $app->themeChoice;

        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));

        self::assertFalse($before->equals($app->themeChoice));
    }

    #[Test]
    public function ctrlUWorksEvenWhileADialogIsOpen(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;

        $app->handleKey(CharKeyEvent::new('u', KeyModifiers::CONTROL));

        self::assertTrue($app->panelsSwapped);
        self::assertSame($dlg, $app->shellDialog);
    }

    #[Test]
    public function ctrlTWorksEvenWhileADialogIsOpen(): void
    {
        $app = $this->makeApp();
        $app->shellDialog = new ShellDialog();
        $dlg = $app->shellDialog;
        $before = $app->themeChoice;

        $app->handleKey(CharKeyEvent::new('t', KeyModifiers::CONTROL));

        self::assertFalse($before->equals($app->themeChoice));
        self::assertSame($dlg, $app->shellDialog);
    }

    #[Test]
    public function hostKeyDialogBeatsPermissionDialog(): void
    {
        $app = $this->makeApp();
        $app->hostKeyDialog = self::makeHostKeyDialog();
        $app->permissionDialog = new PermissionFixDialog('/nonexistent', 0644);
        $dlg = $app->permissionDialog;

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertNull($app->hostKeyDialog);
        self::assertSame($dlg, $app->permissionDialog);
    }

    #[Test]
    public function permissionDialogBeatsPasswordDialog(): void
    {
        $app = $this->makeApp();
        $app->permissionDialog = new PermissionFixDialog('/nonexistent', 0644);
        $app->passwordDialog = new PasswordDialog(self::makeProfile());
        $dlg = $app->passwordDialog;

        $app->handleKey(CharKeyEvent::new('i'));

        self::assertNull($app->permissionDialog);
        self::assertSame($dlg, $app->passwordDialog);
    }

    #[Test]
    public function passwordDialogBeatsDeleteDialog(): void
    {
        $app = $this->makeApp();
        $app->passwordDialog = new PasswordDialog(self::makeProfile());
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [['name' => 'a.txt', 'isDir' => false]]);
        $dlg = $app->deleteDialog;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->passwordDialog);
        self::assertSame($dlg, $app->deleteDialog);
    }

    #[Test]
    public function deleteDialogBeatsCopyConflictDialog(): void
    {
        $app = $this->makeApp();
        $app->deleteDialog = new DeleteDialog(PanelSide::Left, [['name' => 'a.txt', 'isDir' => false]]);
        $app->copyConflictDialog = self::makeCopyConflictDialog();
        $dlg = $app->copyConflictDialog;

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertNull($app->deleteDialog);
        self::assertSame($dlg, $app->copyConflictDialog);
    }

    #[Test]
    public function copyConflictDialogBeatsRenameDialog(): void
    {
        $app = $this->makeApp();
        $app->copyConflictDialog = self::makeCopyConflictDialog();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'original.txt');
        $dlg = $app->renameDialog;

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertNull($app->copyConflictDialog);
        self::assertSame($dlg, $app->renameDialog);
        self::assertSame('original.txt', $dlg->input->value());
    }

    #[Test]
    public function renameDialogBeatsMkdirDialog(): void
    {
        $app = $this->makeApp();
        $app->renameDialog = new RenameDialog(PanelSide::Left, 'original.txt');
        $app->mkdirDialog = new MkdirDialog(PanelSide::Left);
        $dlg = $app->mkdirDialog;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->renameDialog);
        self::assertSame($dlg, $app->mkdirDialog);
        self::assertSame('', $dlg->input->value());
    }

    #[Test]
    public function mkdirDialogBeatsShellDialogBeatsProfileDialog(): void
    {
        $app = $this->makeApp();
        $app->mkdirDialog = new MkdirDialog(PanelSide::Left);
        $app->shellDialog = new ShellDialog();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $shellDlg = $app->shellDialog;
        $profileDlg = $app->profileDialog;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->mkdirDialog);
        self::assertSame($shellDlg, $app->shellDialog);
        self::assertSame($profileDlg, $app->profileDialog);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->shellDialog);
        self::assertSame($profileDlg, $app->profileDialog);
    }

    #[Test]
    public function whenNoDialogIsOpenKeysReachHandleMainKey(): void
    {
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('q'));

        self::assertFalse($app->running);
    }

    private static function makeProfile(): Profile
    {
        return new Profile('test-profile', 'example.com', 22, 'user', AuthMethod::Key);
    }

    private static function makeHostKeyDialog(): HostKeyDialog
    {
        return new HostKeyDialog('example.com', 22, 'aa:bb:cc', 'ssh-ed25519', 'keybytes', self::makeProfile(), null);
    }

    private static function makeCopyConflictDialog(): CopyConflictDialog
    {
        return new CopyConflictDialog(
            PanelSide::Left,
            [new FileEntry('a.txt', 1, null, false)],
            '/scratch/src',
            '/scratch/dest',
            ['a.txt'],
        );
    }
}
