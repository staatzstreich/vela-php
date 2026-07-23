<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\Config\AuthMethod;
use Vela\Config\Profile;
use Vela\Config\ProfileStore;
use Vela\Dialog\NewProfileForm;
use Vela\Dialog\ProfileDialog;
use Vela\Dialog\ProfileDialogMode;

/**
 * Covers the profile dialog (F9/'p'): list navigation, the new/edit form's
 * field state machine, and confirm-delete. Connecting to a selected profile
 * (Enter in list mode -> profileConnectSelected() -> beginConnect() ->
 * doConnect()) is out of scope — it's a real SFTP connection attempt even
 * for key-auth profiles, no fake SftpConnection is possible.
 *
 * Keychain wrinkle, decided in advance: Keychain is final/all-static with no
 * fake/injection point. applyKeychainSave()'s delete branch fires on every
 * ordinary save (NewProfileForm::$savePassword defaults false), and
 * handleProfileConfirmDeleteKey() always calls Keychain::deletePassword() —
 * so some real, harmless, best-effort Keychain shell-out is unavoidable for
 * any save/delete test, not just password-specific ones; that's accepted
 * here (it's exactly what happens on every real save/delete too). What's
 * NOT covered: the real Keychain::savePassword() write path (savePassword
 * = true with a non-empty password) — that's machine-dependent
 * (Keychain::isSupported() differs by OS/CI) and is the one place a test
 * bug could leave a real stray credential in the developer's actual
 * Keychain, so every profile built here keeps savePassword at its default
 * false.
 */
final class AppProfileDialogTest extends AppTestCase
{
    #[Test]
    public function openProfileDialogSurfacesAMalformedProfilesTomlAsAStatusMessageInsteadOfCrashing(): void
    {
        $configDir = "{$this->scratchHome}/.config/vela";
        mkdir($configDir, 0755, true);
        $path = "{$configDir}/profiles.toml";
        file_put_contents($path, "[[profile\nname = ");
        chmod($path, 0600);
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('p'));

        self::assertNull($app->profileDialog);
        self::assertNotNull($app->statusMessage);
    }

    #[Test]
    public function nOpensANewProfileFormWithDefaults(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertSame(ProfileDialogMode::New, $dlg->mode);
        self::assertSame('', $dlg->form->name);
        self::assertSame(0, $dlg->field);
    }

    #[Test]
    public function eOnAnEmptyStoreIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;

        $app->handleKey(CharKeyEvent::new('e'));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
    }

    #[Test]
    public function eOnAnExistingProfileOpensAnEditFormPrefilledFromIt(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(self::storeWithOneProfile());
        $dlg = $app->profileDialog;

        $app->handleKey(CharKeyEvent::new('e'));

        self::assertSame(ProfileDialogMode::Edit, $dlg->mode);
        self::assertSame('test-profile', $dlg->form->name);
        self::assertSame(0, $dlg->editIndex);
    }

    #[Test]
    public function f2FunctionKeyAlsoOpensTheEditForm(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(self::storeWithOneProfile());
        $dlg = $app->profileDialog;

        $app->handleKey(FunctionKeyEvent::new(2));

        self::assertSame(ProfileDialogMode::Edit, $dlg->mode);
    }

    #[Test]
    public function dOnAnEmptyStoreIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;

        $app->handleKey(CharKeyEvent::new('d'));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
    }

    #[Test]
    public function dOpensAConfirmDeletePromptForTheSelectedProfile(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(self::storeWithOneProfile());
        $dlg = $app->profileDialog;

        $app->handleKey(CharKeyEvent::new('d'));

        self::assertSame(ProfileDialogMode::ConfirmDelete, $dlg->mode);
        self::assertSame(0, $dlg->deleteIndex);
    }

    #[Test]
    public function deleteKeyAlsoOpensTheConfirmDeletePrompt(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(self::storeWithOneProfile());
        $dlg = $app->profileDialog;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Delete));

        self::assertSame(ProfileDialogMode::ConfirmDelete, $dlg->mode);
    }

    #[Test]
    public function escFromListModeClosesTheProfileDialogEntirely(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->profileDialog);
    }

    #[Test]
    public function upDownMoveListSelectionWithinBounds(): void
    {
        $store = new ProfileStore();
        $store->add(new Profile('a', 'host', 22, 'user', AuthMethod::Key));
        $store->add(new Profile('b', 'host', 22, 'user', AuthMethod::Key));
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog($store);
        $dlg = $app->profileDialog;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Down));
        self::assertSame(1, $dlg->listSelected);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Down));
        self::assertSame(1, $dlg->listSelected);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Up));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Up));
        self::assertSame(0, $dlg->listSelected);
    }

    #[Test]
    public function tabSkipsKeyPathFieldWhenAuthIsPassword(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->form->auth = AuthMethod::Password;
        $dlg->field = NewProfileForm::AUTH;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Tab));

        self::assertSame(NewProfileForm::REMOTE_PATH, $dlg->field);
    }

    #[Test]
    public function tabSkipsSavePasswordAndPasswordFieldsWhenAuthIsKey(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::LOCAL_PATH;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Tab));

        self::assertSame(NewProfileForm::NAME, $dlg->field);
    }

    #[Test]
    public function backTabMovesToThePreviousVisibleField(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::REMOTE_PATH;

        $app->handleKey(CodedKeyEvent::new(KeyCode::BackTab));

        self::assertSame(NewProfileForm::KEY_PATH, $dlg->field);
    }

    #[Test]
    public function typingAppendsToTheCurrentTextField(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::NAME;

        $app->handleKey(CharKeyEvent::new('x'));
        $app->handleKey(CharKeyEvent::new('y'));

        self::assertSame('xy', $dlg->form->name);
    }

    #[Test]
    public function backspaceRemovesTheLastCharacterMultibyteSafe(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::NAME;
        $dlg->form->name = 'ü';

        $app->handleKey(CodedKeyEvent::new(KeyCode::Backspace));

        self::assertSame('', $dlg->form->name);
    }

    #[Test]
    public function spaceOnTheAuthFieldTogglesKeyAndPassword(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::AUTH;

        $app->handleKey(CharKeyEvent::new(' '));
        self::assertSame(AuthMethod::Password, $dlg->form->auth);

        $app->handleKey(CharKeyEvent::new(' '));
        self::assertSame(AuthMethod::Key, $dlg->form->auth);
    }

    #[Test]
    public function spaceOnTheSavePasswordFieldTogglesTheBoolean(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::SAVE_PASSWORD;

        $app->handleKey(CharKeyEvent::new(' '));
        self::assertTrue($dlg->form->savePassword);

        $app->handleKey(CharKeyEvent::new(' '));
        self::assertFalse($dlg->form->savePassword);
    }

    #[Test]
    public function nonDigitCharsAreIgnoredOnThePortField(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->field = NewProfileForm::PORT;

        $app->handleKey(CharKeyEvent::new('x'));
        self::assertSame('22', $dlg->form->port);

        $app->handleKey(CharKeyEvent::new('5'));
        self::assertSame('225', $dlg->form->port);
    }

    #[Test]
    public function escFromFormReturnsToListModeWithoutSavingAnyChange(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->form->name = 'unsaved';

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
        self::assertSame([], $dlg->store->profiles);
    }

    #[Test]
    public function enterWithAnEmptyRequiredFieldShowsAValidationMessageAndStaysInFormMode(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame(ProfileDialogMode::New, $dlg->mode);
        self::assertSame('Name, Host und User dürfen nicht leer sein', $app->statusMessage);
        self::assertSame([], $dlg->store->profiles);
    }

    #[Test]
    public function enterOnAValidNewKeyAuthProfileSavesPersistsAndReturnsToListMode(): void
    {
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog(new ProfileStore());
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::New;
        $dlg->form->name = 'new-profile';
        $dlg->form->host = 'example.com';
        $dlg->form->user = 'someuser';

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
        self::assertCount(1, $dlg->store->profiles);
        self::assertSame('new-profile', $dlg->store->profiles[0]->name);
        self::assertSame("Profil 'new-profile' gespeichert", $app->statusMessage);

        $reloaded = ProfileStore::load();
        self::assertCount(1, $reloaded->profiles);
        self::assertSame('new-profile', $reloaded->profiles[0]->name);
    }

    #[Test]
    public function enterOnAValidEditedProfileUpdatesInPlaceAndPersists(): void
    {
        $store = self::storeWithOneProfile();
        $store->save();
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog($store);
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::Edit;
        $dlg->editIndex = 0;
        $dlg->form = NewProfileForm::fromProfile($store->profiles[0]);
        $dlg->form->name = 'renamed-profile';

        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
        self::assertCount(1, $dlg->store->profiles);
        self::assertSame('renamed-profile', $dlg->store->profiles[0]->name);

        $reloaded = ProfileStore::load();
        self::assertSame('renamed-profile', $reloaded->profiles[0]->name);
    }

    #[Test]
    public function confirmDeleteRemovesTheProfileAndPersists(): void
    {
        $store = self::storeWithOneProfile();
        $store->save();
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog($store);
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::ConfirmDelete;
        $dlg->deleteIndex = 0;

        $app->handleKey(CharKeyEvent::new('y'));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
        self::assertSame([], $dlg->store->profiles);
        self::assertSame('Profil gelöscht', $app->statusMessage);

        $reloaded = ProfileStore::load();
        self::assertSame([], $reloaded->profiles);
    }

    #[Test]
    public function cancelConfirmDeleteWithNReturnsToListModeUntouched(): void
    {
        $store = self::storeWithOneProfile();
        $app = $this->makeApp();
        $app->profileDialog = new ProfileDialog($store);
        $dlg = $app->profileDialog;
        $dlg->mode = ProfileDialogMode::ConfirmDelete;
        $dlg->deleteIndex = 0;

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertSame(ProfileDialogMode::ListMode, $dlg->mode);
        self::assertCount(1, $dlg->store->profiles);
    }

    private static function storeWithOneProfile(): ProfileStore
    {
        $store = new ProfileStore();
        $store->add(new Profile('test-profile', 'example.com', 22, 'someuser', AuthMethod::Key));

        return $store;
    }
}
