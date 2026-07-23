<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\FunctionKeyEvent;
use PHPUnit\Framework\Attributes\Test;
use Vela\ActivePanel;
use Vela\Dialog\EditRequest;

/**
 * Covers Edit (F4) — App::prepareEdit()'s local branch and App::finishEdit()'s
 * local branch. The remote branches (downloading to a temp dir, re-uploading
 * on finish) need a real SftpConnection and are out of scope.
 *
 * Non-obvious finding worth documenting in the test itself: prepareEdit()
 * decides local-vs-remote purely from `$this->active === ActivePanel::Left`,
 * not from whether the active panel is actually showing local files — so F4
 * on the right panel while disconnected does NOT fall back to a local edit
 * the way copy/rename/mkdir/delete's guards do. It falls through to the
 * `$this->sftp === null` check and returns silently: no pendingEdit, no
 * status message at all. See prepareEditOnTheRightPanelWhileDisconnectedIsASilentNoOp().
 */
final class AppEditTest extends AppTestCase
{
    #[Test]
    public function prepareEditOnANormalFileSetsPendingEditToALocalRequest(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(4));

        self::assertNotNull($app->pendingEdit);
        self::assertFalse($app->pendingEdit->isRemote());
        self::assertSame("{$this->scratchLeft}/a.txt", $app->pendingEdit->editPath);
    }

    #[Test]
    public function prepareEditOnADirectoryIsANoOpWithAStatusMessage(): void
    {
        mkdir("{$this->scratchLeft}/sub");
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'sub');

        $app->handleKey(FunctionKeyEvent::new(4));

        self::assertNull($app->pendingEdit);
        self::assertSame('Kein bearbeitbarer Eintrag ausgewählt', $app->statusMessage);
    }

    #[Test]
    public function prepareEditOnDotDotIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, '..');

        $app->handleKey(FunctionKeyEvent::new(4));

        self::assertNull($app->pendingEdit);
        self::assertSame('Kein bearbeitbarer Eintrag ausgewählt', $app->statusMessage);
    }

    #[Test]
    public function prepareEditOnTheRightPanelWhileDisconnectedIsASilentNoOp(): void
    {
        file_put_contents("{$this->scratchRight}/a.txt", 'x');
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;
        $app->right->selected = self::mustIndexOf($app->right->entries, 'a.txt');

        $app->handleKey(FunctionKeyEvent::new(4));

        self::assertNull($app->pendingEdit);
        self::assertNull($app->statusMessage);
    }

    #[Test]
    public function finishEditOnALocalRequestReloadsTheLeftPanelAndSetsAStatusMessage(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        // Added after the panel's initial loadLocal() — only visible if
        // finishEdit() genuinely reloads the panel.
        file_put_contents("{$this->scratchLeft}/added-after-load.txt", 'x');

        $app->finishEdit(EditRequest::local("{$this->scratchLeft}/a.txt"));

        self::assertSame('Editor geschlossen', $app->statusMessage);
        self::assertNotNull(self::indexOf($app->left->entries, 'added-after-load.txt'));
    }

    #[Test]
    public function finishEditNeverTouchesSftpForALocalRequest(): void
    {
        $app = $this->makeApp();

        $app->finishEdit(EditRequest::local("{$this->scratchLeft}/does-not-need-to-exist.txt"));

        self::assertNull($app->sftp);
    }
}
