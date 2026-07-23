<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\Dialog\PermissionFixDialog;

/**
 * Covers the permission-fix dialog (App::handlePermissionDialogKey()), plus
 * App::__construct()'s auto-open path: ProfileStore::load() throwing
 * UnsafePermissionsException when ~/.config/vela/profiles.toml isn't mode
 * 0600 is caught in the constructor and turned straight into an open
 * PermissionFixDialog, before any key is even pressed.
 */
final class AppPermissionDialogTest extends AppTestCase
{
    #[Test]
    public function fFixesThePermissionsAndDismissesTheDialog(): void
    {
        $path = "{$this->scratchLeft}/some-file";
        file_put_contents($path, 'x');
        chmod($path, 0644);
        $app = $this->makeApp();
        $app->permissionDialog = new PermissionFixDialog($path, 0o644);

        $app->handleKey(CharKeyEvent::new('f'));

        self::assertNull($app->permissionDialog);
        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    #[Test]
    public function iDismissesWithoutTouchingPermissions(): void
    {
        $path = "{$this->scratchLeft}/some-file";
        file_put_contents($path, 'x');
        chmod($path, 0644);
        $app = $this->makeApp();
        $app->permissionDialog = new PermissionFixDialog($path, 0o644);

        $app->handleKey(CharKeyEvent::new('i'));

        self::assertNull($app->permissionDialog);
        self::assertSame(0o644, fileperms($path) & 0o777);
    }

    #[Test]
    public function escAlsoDismissesWithoutTouchingPermissions(): void
    {
        $path = "{$this->scratchLeft}/some-file";
        file_put_contents($path, 'x');
        chmod($path, 0644);
        $app = $this->makeApp();
        $app->permissionDialog = new PermissionFixDialog($path, 0o644);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->permissionDialog);
        self::assertSame(0o644, fileperms($path) & 0o777);
    }

    #[Test]
    public function anyOtherKeyIsIgnoredAndTheDialogStaysOpen(): void
    {
        $path = "{$this->scratchLeft}/some-file";
        file_put_contents($path, 'x');
        $app = $this->makeApp();
        $app->permissionDialog = new PermissionFixDialog($path, 0o644);
        $dlg = $app->permissionDialog;

        $app->handleKey(CharKeyEvent::new('x'));

        self::assertSame($dlg, $app->permissionDialog);
    }

    #[Test]
    public function constructingAppWithLooselyPermissionedProfilesTomlAutoOpensTheDialog(): void
    {
        $configDir = "{$this->scratchHome}/.config/vela";
        mkdir($configDir, 0755, true);
        $path = "{$configDir}/profiles.toml";
        file_put_contents($path, '');
        chmod($path, 0644);

        $app = $this->makeApp();

        self::assertNotNull($app->permissionDialog);
        self::assertSame($path, $app->permissionDialog->path);
        self::assertSame(0o644, $app->permissionDialog->mode);
    }

    #[Test]
    public function constructingAppWithProperlyPermissionedProfilesTomlNeverOpensTheDialog(): void
    {
        $configDir = "{$this->scratchHome}/.config/vela";
        mkdir($configDir, 0755, true);
        $path = "{$configDir}/profiles.toml";
        file_put_contents($path, '');
        chmod($path, 0600);

        $app = $this->makeApp();

        self::assertNull($app->permissionDialog);
    }
}
