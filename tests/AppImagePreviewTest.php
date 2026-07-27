<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\ActivePanel;
use Vela\Dialog\ImagePreviewDialog;
use Vela\Fs\FileEntry;

/**
 * Covers App::openImagePreview()'s local branch and
 * handleImagePreviewDialogKey()'s dismiss-key matrix. No Rust original — see
 * ImagePreviewDialog's docblock.
 *
 * Out of scope, same reasoning as every other SFTP-only path in this
 * project: the remote-download branch (needs a real SftpConnection) and the
 * actual Imagick resize call inside openImagePreview() (needs ext-imagick,
 * not installed in this environment). Whether ext-imagick happens to be
 * installed when this suite runs changes whether openImagePreview() creates
 * a temp dir for the resize step even for a *local* file — tests that open
 * a real dialog account for this by branching on the observed
 * $dlg->tempDir instead of asserting a fixed expectation, so they pass
 * identically either way.
 *
 * closeImagePreview()'s cleanup logic is tested directly against a
 * manually-constructed ImagePreviewDialog (bypassing openImagePreview()
 * entirely), so it's exercised deterministically regardless of ext-imagick.
 */
final class AppImagePreviewTest extends AppTestCase
{
    #[Test]
    public function openImagePreviewOnANonImageFileIsANoOpWithAStatusMessage(): void
    {
        file_put_contents("{$this->scratchLeft}/notes.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'notes.txt');

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNull($app->imagePreviewDialog);
        self::assertSame('Kein bearbeitbarer Eintrag ausgewählt', $app->statusMessage);
    }

    #[Test]
    public function openImagePreviewOnADirectoryIsANoOp(): void
    {
        mkdir("{$this->scratchLeft}/sub");
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'sub');

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNull($app->imagePreviewDialog);
        self::assertSame('Kein bearbeitbarer Eintrag ausgewählt', $app->statusMessage);
    }

    #[Test]
    public function openImagePreviewOnDotDotIsANoOp(): void
    {
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, '..');

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNull($app->imagePreviewDialog);
        self::assertSame('Kein bearbeitbarer Eintrag ausgewählt', $app->statusMessage);
    }

    #[Test]
    public function openImagePreviewRejectsAFileOverTheSizeCap(): void
    {
        $app = $this->makeApp();
        // Injected directly (not written to disk) — the size cap is checked
        // before any filesystem access, so the file doesn't need to exist.
        $app->left->entries[] = new FileEntry('huge.png', 30 * 1024 * 1024, null, false);
        $app->left->selected = count($app->left->entries) - 1;

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNull($app->imagePreviewDialog);
        self::assertSame('Datei zu groß für Vorschau (>25 MB)', $app->statusMessage);
    }

    #[Test]
    public function openImagePreviewOnAValidLocalImageOpensTheDialog(): void
    {
        $path = "{$this->scratchLeft}/photo.png";
        file_put_contents($path, self::tinyPngBytes());
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'photo.png');

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNotNull($app->imagePreviewDialog);
        $dlg = $app->imagePreviewDialog;
        self::assertSame('photo.png', $dlg->entryName);
        self::assertFileExists($dlg->previewPath);
        if ($dlg->tempDir === null) {
            self::assertSame($path, $dlg->previewPath);
        } else {
            self::assertTrue(str_starts_with($dlg->previewPath, $dlg->tempDir));
            self::assertFileExists($path);
        }

        // If ext-imagick is installed, openImagePreview() just created a real
        // scratch dir under the system temp dir (deliberately outside
        // AppTestCase's scratch roots, matching what the app does for a real
        // user) — close it via the real code path so it's actually cleaned up
        // instead of leaking on every test run.
        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));
    }

    #[Test]
    public function openImagePreviewIsCaseInsensitiveOnExtension(): void
    {
        $path = "{$this->scratchLeft}/PHOTO.PNG";
        file_put_contents($path, self::tinyPngBytes());
        $app = $this->makeApp();
        $app->left->selected = self::mustIndexOf($app->left->entries, 'PHOTO.PNG');

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNotNull($app->imagePreviewDialog);
        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));
    }

    #[Test]
    public function openImagePreviewOnTheRightPanelWhileDisconnectedTreatsItAsLocal(): void
    {
        $path = "{$this->scratchRight}/photo.png";
        file_put_contents($path, self::tinyPngBytes());
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;
        $app->right->selected = self::mustIndexOf($app->right->entries, 'photo.png');

        $app->handleKey(CharKeyEvent::new('v'));

        self::assertNotNull($app->imagePreviewDialog);
        self::assertNull($app->sftp);
        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));
    }

    #[Test]
    public function handleImagePreviewDialogKeyClosesOnEsc(): void
    {
        $app = $this->makeApp();
        $app->imagePreviewDialog = new ImagePreviewDialog('photo.png', "{$this->scratchLeft}/photo.png", null);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->imagePreviewDialog);
    }

    #[Test]
    public function handleImagePreviewDialogKeyClosesOnLowercaseQ(): void
    {
        $app = $this->makeApp();
        $app->imagePreviewDialog = new ImagePreviewDialog('photo.png', "{$this->scratchLeft}/photo.png", null);

        $app->handleKey(CharKeyEvent::new('q'));

        self::assertNull($app->imagePreviewDialog);
    }

    #[Test]
    public function handleImagePreviewDialogKeyClosesOnUppercaseQ(): void
    {
        $app = $this->makeApp();
        $app->imagePreviewDialog = new ImagePreviewDialog('photo.png', "{$this->scratchLeft}/photo.png", null);

        $app->handleKey(CharKeyEvent::new('Q'));

        self::assertNull($app->imagePreviewDialog);
    }

    #[Test]
    public function handleImagePreviewDialogKeySwallowsOtherKeys(): void
    {
        $app = $this->makeApp();
        $app->imagePreviewDialog = new ImagePreviewDialog('photo.png', "{$this->scratchLeft}/photo.png", null);

        $app->handleKey(CharKeyEvent::new(' '));

        self::assertNotNull($app->imagePreviewDialog);
    }

    #[Test]
    public function closingCleansUpTheTempDirWhenOneWasCreated(): void
    {
        $tempDir = "{$this->scratchHome}/fake-preview-temp";
        mkdir($tempDir);
        $previewPath = "{$tempDir}/preview-photo.png";
        file_put_contents($previewPath, 'x');
        $app = $this->makeApp();
        $app->imagePreviewDialog = new ImagePreviewDialog('photo.png', $previewPath, $tempDir);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertDirectoryDoesNotExist($tempDir);
    }

    #[Test]
    public function closingNeverTouchesTheOriginalFileWhenTempDirIsNull(): void
    {
        $path = "{$this->scratchLeft}/photo.png";
        file_put_contents($path, 'x');
        $app = $this->makeApp();
        $app->imagePreviewDialog = new ImagePreviewDialog('photo.png', $path, null);

        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertFileExists($path);
    }

    /** A valid, minimal 1x1 transparent PNG — needed so openImagePreview()'s Imagick read actually succeeds when ext-imagick is installed. */
    private static function tinyPngBytes(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertNotFalse($decoded);

        return $decoded;
    }
}
