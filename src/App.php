<?php

declare(strict_types=1);

namespace Vela;

use Imagick;
use ImagickPixel;
use RuntimeException;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\FunctionKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use Throwable;
use Vela\Config\AuthMethod;
use Vela\Config\Profile;
use Vela\Config\ProfileStore;
use Vela\Config\UnsafePermissionsException;
use Vela\Connection\SftpConnection;
use Vela\Connection\UnknownHostKeyException;
use Vela\Config\Keychain;
use Vela\Dialog\CopyConflictDialog;
use Vela\Dialog\DeleteDialog;
use Vela\Dialog\EditRequest;
use Vela\Dialog\HostKeyDialog;
use Vela\Dialog\ImagePreviewDialog;
use Vela\Dialog\MkdirDialog;
use Vela\Dialog\NewProfileForm;
use Vela\Dialog\PanelSide;
use Vela\Dialog\PasswordDialog;
use Vela\Dialog\PermissionFixDialog;
use Vela\Dialog\ProfileDialog;
use Vela\Dialog\ProfileDialogMode;
use Vela\Dialog\RenameDialog;
use Vela\Dialog\ShellDialog;
use Vela\Fs\FileEntry;
use Vela\Fs\ImageScaling;
use Vela\Fs\PanelState;
use Vela\Transfer\TransferEngine;
use Vela\Transfer\TransferProgress;
use Vela\Transfer\TransferState;
use Vela\Theme\ThemeChoice;
use Vela\Theme\ThemeStore;

/**
 * Central app state and key dispatch. Mirrors vela's src/app.rs App struct
 * plus main.rs's handle_events() dialog-priority chain, folded into one
 * handleKey() entry point instead of a free function taking &mut App.
 */
final class App
{
    public bool $running = true;

    public ActivePanel $active = ActivePanel::Left;

    public ?string $statusMessage = null;

    public PanelState $left;

    public PanelState $right;

    public ?SftpConnection $sftp = null;

    public ?TransferProgress $activeTransfer = null;

    public string $activeTransferVerb = '';

    public bool $helpVisible = false;

    /** Incremental filename search ('/' key) — active while typing the query. */
    public ?SearchState $search = null;

    /** Last confirmed search query, reused by 'n' / 'N' to repeat the search. */
    public ?string $lastSearch = null;

    public ?RenameDialog $renameDialog = null;

    public ?MkdirDialog $mkdirDialog = null;

    public ?DeleteDialog $deleteDialog = null;

    public ?CopyConflictDialog $copyConflictDialog = null;

    public ?PasswordDialog $passwordDialog = null;

    public ?HostKeyDialog $hostKeyDialog = null;

    public ?PermissionFixDialog $permissionDialog = null;

    public ?ShellDialog $shellDialog = null;

    public ?ProfileDialog $profileDialog = null;

    public ?ImagePreviewDialog $imagePreviewDialog = null;

    public ThemeChoice $themeChoice;

    /** Purely visual — which physical side shows the local vs. remote panel. The data model is unchanged. */
    public bool $panelsSwapped = false;

    /** Set by F4; the main loop takes it, suspends the TUI, runs $EDITOR, then calls finishEdit(). */
    public ?EditRequest $pendingEdit = null;

    /**
     * Refreshed once per frame by the main loop from Display::viewportArea()
     * — openImagePreview() needs the real terminal size to pick a resize
     * target that's never smaller than the dialog's actual render
     * resolution. Defaults matter for anything that never sets these (tests,
     * or before the first frame): php-tui's Canvas/ImagePainter has no
     * interpolation, so resizing a source image *smaller* than the grid it's
     * painted onto leaves regularly-spaced unpainted target columns/rows
     * (visible as blank/white stripes, or a "tiled" look on patterned
     * images) — confirmed by reconstructing a real preview render frame from
     * its raw ANSI output during manual testing.
     */
    public int $viewportCols = 80;

    public int $viewportRows = 24;

    public function __construct(string $leftPath, string $rightPath)
    {
        $this->left = new PanelState($leftPath);
        $this->right = new PanelState($rightPath);
        // Both panels start out browsing the local filesystem; the right
        // one switches to the remote listing once attachSftp()/doConnect()
        // runs. This enables local-to-local copy (F5/F6 while disconnected)
        // — see copyToRight()/copyToLeft() and TransferEngine::copyBatch().
        $this->left->loadLocal();
        $this->right->loadLocal();

        ThemeStore::ensureThemes();
        $this->themeChoice = ThemeStore::loadThemeChoice();

        try {
            ProfileStore::load();
        } catch (UnsafePermissionsException $e) {
            $this->permissionDialog = new PermissionFixDialog($e->path, $e->mode);
        } catch (Throwable) {
            // Malformed profiles.toml etc. — surfaced when the profile dialog opens instead.
        }
    }

    public function isConnected(): bool
    {
        return $this->sftp !== null;
    }

    /** Right panel switches from local browsing to the remote listing. */
    public function attachSftp(SftpConnection $sftp): void
    {
        $this->sftp = $sftp;
        $this->right->path = $sftp->remotePath;
        $this->right->entries = $sftp->listDir();
        $this->right->selected = 0;
        $this->right->marked = [];
    }

    public function activePanel(): PanelState
    {
        return $this->active === ActivePanel::Left ? $this->left : $this->right;
    }

    public function togglePanel(): void
    {
        $this->active = $this->active->toggle();
    }

    public function quit(): void
    {
        $this->running = false;
    }

    /** Enter incremental search mode, anchored at the active panel's current selection. */
    public function openSearch(): void
    {
        $this->search = new SearchState('', $this->activePanel()->selected);
    }

    /**
     * Append a character to the search query and jump to the nearest match
     * from the search's origin, if any.
     */
    public function searchPush(string $char): void
    {
        if ($this->search !== null) {
            $this->search->query .= $char;
        }
        $this->searchReposition();
    }

    /** Remove the last character from the search query and re-jump. */
    public function searchBackspace(): void
    {
        if ($this->search !== null && $this->search->query !== '') {
            $this->search->query = mb_substr($this->search->query, 0, -1);
        }
        $this->searchReposition();
    }

    /** Recompute the match for the current query starting from the search origin. */
    private function searchReposition(): void
    {
        $search = $this->search;
        if ($search === null) {
            return;
        }
        if ($search->query === '') {
            $this->activePanel()->selected = $search->origin;

            return;
        }
        $idx = $this->activePanel()->findMatch($search->query, $search->origin, true);
        if ($idx !== null) {
            $this->activePanel()->selected = $idx;
        }
    }

    /**
     * Confirm the current search (Enter): keep the cursor where it landed
     * and remember the query for 'n' / 'N'.
     */
    public function confirmSearch(): void
    {
        $search = $this->search;
        $this->search = null;
        if ($search !== null && $search->query !== '') {
            $this->lastSearch = $search->query;
        }
    }

    /**
     * Cancel the current search (Esc): revert the cursor to where it was
     * before the search started.
     */
    public function cancelSearch(): void
    {
        $search = $this->search;
        $this->search = null;
        if ($search !== null) {
            $this->activePanel()->selected = $search->origin;
        }
    }

    /** Repeat the last confirmed search forward ('n'). */
    public function searchNext(): void
    {
        if ($this->lastSearch === null) {
            return;
        }
        $panel = $this->activePanel();
        if ($panel->entries === []) {
            return;
        }
        $from = ($panel->selected + 1) % count($panel->entries);
        $idx = $panel->findMatch($this->lastSearch, $from, true);
        if ($idx !== null) {
            $panel->selected = $idx;
        }
    }

    /** Repeat the last confirmed search backward ('N'). */
    public function searchPrev(): void
    {
        if ($this->lastSearch === null) {
            return;
        }
        $panel = $this->activePanel();
        if ($panel->entries === []) {
            return;
        }
        $from = ($panel->selected + count($panel->entries) - 1) % count($panel->entries);
        $idx = $panel->findMatch($this->lastSearch, $from, false);
        if ($idx !== null) {
            $panel->selected = $idx;
        }
    }

    /**
     * Single entry point for all key input, mirroring main.rs's
     * handle_events() dialog-priority chain: F1 (help) always wins; while
     * help is open only Esc closes it; otherwise the first open dialog in
     * this order gets the key; only if none are open do main-panel keys
     * fire. $onTransferTick is threaded through to F5/F6 for progress-bar
     * redraws (see Vela\Transfer\TransferEngine's docblock).
     */
    public function handleKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ?callable $onTransferTick = null): void
    {
        if ($event instanceof FunctionKeyEvent && $event->number === 1) {
            $this->helpVisible = !$this->helpVisible;

            return;
        }

        if ($this->helpVisible) {
            // Rust only binds Esc here, but php-tui/term can occasionally
            // drop a lone Esc right after an F-key sequence (a variant of
            // the buffer bug patches/php-tui-term-event-parser-more-flag.patch
            // fixes for arrow keys — this one's a different trigger and
            // isn't patched). 'q' as a second way out is cheap insurance.
            if (($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc)
                || ($event instanceof CharKeyEvent && $event->char === 'q')) {
                $this->helpVisible = false;
            }

            return;
        }

        // Ctrl+U / Ctrl+S — swap panels visually; Ctrl+T — cycle theme.
        // Both work from any mode, same as in main.rs's handle_events().
        if ($event instanceof CharKeyEvent && ($event->modifiers & KeyModifiers::CONTROL) !== 0) {
            if ($event->char === 'u' || $event->char === 's') {
                $this->panelsSwapped = !$this->panelsSwapped;

                return;
            }
            if ($event->char === 't') {
                $this->cycleTheme();

                return;
            }
        }

        if ($this->hostKeyDialog !== null) {
            $this->handleHostKeyDialogKey($event, $this->hostKeyDialog);
        } elseif ($this->permissionDialog !== null) {
            $this->handlePermissionDialogKey($event, $this->permissionDialog);
        } elseif ($this->passwordDialog !== null) {
            $this->handlePasswordDialogKey($event, $this->passwordDialog);
        } elseif ($this->deleteDialog !== null) {
            $this->handleDeleteDialogKey($event, $this->deleteDialog);
        } elseif ($this->copyConflictDialog !== null) {
            $this->handleCopyConflictDialogKey($event, $this->copyConflictDialog, $onTransferTick);
        } elseif ($this->renameDialog !== null) {
            $this->handleRenameDialogKey($event, $this->renameDialog);
        } elseif ($this->mkdirDialog !== null) {
            $this->handleMkdirDialogKey($event, $this->mkdirDialog);
        } elseif ($this->shellDialog !== null) {
            $this->handleShellDialogKey($event, $this->shellDialog);
        } elseif ($this->profileDialog !== null) {
            $this->handleProfileDialogKey($event, $this->profileDialog);
        } elseif ($this->imagePreviewDialog !== null) {
            $this->handleImagePreviewDialogKey($event, $this->imagePreviewDialog);
        } elseif ($this->search !== null) {
            $this->handleSearchKey($event);
        } else {
            $this->handleMainKey($event, $onTransferTick);
        }
    }

    private function handleSearchKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event): void
    {
        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Esc => $this->cancelSearch(),
                KeyCode::Enter => $this->confirmSearch(),
                KeyCode::Backspace => $this->searchBackspace(),
                default => null,
            };

            return;
        }

        if ($event instanceof CharKeyEvent) {
            $this->searchPush($event->char);
        }
    }

    private function handleMainKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ?callable $onTransferTick): void
    {
        if ($event instanceof CharKeyEvent) {
            match ($event->char) {
                'q' => $this->quit(),
                ' ' => $this->markAndAdvance(),
                '*' => $this->activePanel()->markAll(),
                '!' => $this->openShellDialog(),
                't' => $this->openTailDialog(),
                'p' => $this->openProfileDialog(),
                'v' => $this->openImagePreview(),
                '/' => $this->openSearch(),
                'n' => $this->searchNext(),
                'N' => $this->searchPrev(),
                default => null,
            };

            return;
        }

        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Tab => $this->togglePanel(),
                KeyCode::Up => $this->activePanel()->moveUp(),
                KeyCode::Down => $this->activePanel()->moveDown(),
                KeyCode::Enter => $this->enterActive(),
                KeyCode::Backspace => $this->goUpActive(),
                KeyCode::Esc => $this->quit(),
                default => null,
            };

            return;
        }

        match ($event->number) {
            2 => $this->openRenameDialog(),
            3 => $this->disconnectSftp(),
            4 => $this->prepareEdit(),
            5 => $this->isConnected() ? $this->uploadActive($onTransferTick) : $this->copyToRight($onTransferTick),
            6 => $this->isConnected() ? $this->downloadActive($onTransferTick) : $this->copyToLeft($onTransferTick),
            7 => $this->openMkdirDialog(),
            8 => $this->openDeleteDialog(),
            9 => $this->openProfileDialog(),
            10 => $this->quit(),
            default => null,
        };
    }

    private function markAndAdvance(): void
    {
        $this->activePanel()->toggleMark();
        $this->activePanel()->moveDown();
    }

    private function enterActive(): void
    {
        $this->tryRun(function (): void {
            $sftp = $this->sftp;
            if ($this->active === ActivePanel::Right && $sftp !== null) {
                $entry = $this->right->entries[$this->right->selected] ?? null;
                if ($entry === null || !$entry->isDir) {
                    return;
                }
                $this->setRemoteListing($sftp, $sftp->enterDir($entry->name));
            } else {
                $this->activePanel()->enterSelected();
            }
        });
    }

    private function goUpActive(): void
    {
        $this->tryRun(function (): void {
            $sftp = $this->sftp;
            if ($this->active === ActivePanel::Right && $sftp !== null) {
                $this->setRemoteListing($sftp, $sftp->goUp());
            } else {
                $this->activePanel()->goUp();
            }
        });
    }

    /**
     * Upload the marked left-panel entries (or the highlighted entry when
     * nothing is marked) to the current remote directory. No-op when not
     * connected. Runs synchronously — $onTick is called between chunks so
     * the caller can redraw a progress bar; regular input stays blocked
     * meanwhile, but Esc is polled from within $onTick and cancels (see
     * TransferEngine::tick()/TransferCancelledException).
     */
    private function uploadActive(?callable $onTick = null): void
    {
        $sftp = $this->sftp;
        if ($sftp === null) {
            return;
        }
        $entries = self::entriesToTransfer($this->left);
        if ($entries === []) {
            return;
        }

        $localBase = $this->left->path;
        $remoteDir = $this->right->path;
        $this->left->clearMarks();

        $totalFiles = max(1, array_sum(array_map(
            fn (FileEntry $e): int => TransferEngine::countLocalFiles(self::joinLocal($localBase, $e->name)),
            $entries,
        )));

        $progress = new TransferProgress($totalFiles);
        $this->activeTransfer = $progress;
        $this->activeTransferVerb = 'Upload';

        TransferEngine::uploadBatch($sftp, $entries, $localBase, $remoteDir, $progress, $onTick);

        $this->activeTransfer = null;
        if ($progress->state === TransferState::Done) {
            $this->statusMessage = 'Upload abgeschlossen';
            $this->tryRun(fn () => $this->setRemoteListing($sftp, $sftp->listDir()));
        } elseif ($progress->state === TransferState::Cancelled) {
            $this->statusMessage = 'Upload abgebrochen';
            $this->tryRun(fn () => $this->setRemoteListing($sftp, $sftp->listDir()));
        } else {
            $this->statusMessage = 'Upload fehlgeschlagen: ' . ($progress->errorMessage ?? 'unbekannter Fehler');
        }
    }

    /**
     * Download the marked right-panel entries (or the highlighted entry
     * when nothing is marked) into the current local directory.
     */
    private function downloadActive(?callable $onTick = null): void
    {
        $sftp = $this->sftp;
        if ($sftp === null) {
            return;
        }
        $entries = self::entriesToTransfer($this->right);
        if ($entries === []) {
            return;
        }

        $remoteDir = $this->right->path;
        $localDir = $this->left->path;
        $this->right->clearMarks();

        $totalFiles = max(1, array_sum(array_map(
            fn (FileEntry $e): int => TransferEngine::countRemoteFiles($sftp, $sftp->joinRemotePath($remoteDir, $e->name)),
            $entries,
        )));

        $progress = new TransferProgress($totalFiles);
        $this->activeTransfer = $progress;
        $this->activeTransferVerb = 'Download';

        TransferEngine::downloadBatch($sftp, $entries, $remoteDir, $localDir, $progress, $onTick);

        $this->activeTransfer = null;
        if ($progress->state === TransferState::Done) {
            $this->statusMessage = 'Download abgeschlossen';
            $this->tryRun(fn () => $this->left->loadLocal());
        } elseif ($progress->state === TransferState::Cancelled) {
            $this->statusMessage = 'Download abgebrochen';
            $this->tryRun(fn () => $this->left->loadLocal());
        } else {
            $this->statusMessage = 'Download fehlgeschlagen: ' . ($progress->errorMessage ?? 'unbekannter Fehler');
        }
    }

    // -------------------------------------------------------------------
    // Local-to-local copy (F5/F6 while disconnected)
    // -------------------------------------------------------------------

    private function copyToRight(?callable $onTick = null): void
    {
        if ($this->sftp !== null) {
            return;
        }
        $this->runLocalCopy(PanelSide::Left, $this->left, $this->right, $onTick);
    }

    private function copyToLeft(?callable $onTick = null): void
    {
        if ($this->sftp !== null) {
            return;
        }
        $this->runLocalCopy(PanelSide::Right, $this->right, $this->left, $onTick);
    }

    private function runLocalCopy(PanelSide $sourceSide, PanelState $source, PanelState $dest, ?callable $onTick): void
    {
        $entries = self::entriesToTransfer($source);
        if ($entries === []) {
            return;
        }

        $conflicts = TransferEngine::findConflicts($entries, $dest->path);
        if ($conflicts !== []) {
            $this->copyConflictDialog = new CopyConflictDialog($sourceSide, $entries, $source->path, $dest->path, $conflicts);

            return;
        }

        $this->performCopy($sourceSide, $entries, $source->path, $dest->path, $onTick);
    }

    private function handleCopyConflictDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, CopyConflictDialog $dlg, ?callable $onTick): void
    {
        if ($event instanceof CharKeyEvent) {
            match ($event->char) {
                'y', 'Y' => $this->confirmCopy($dlg, $onTick),
                'n', 'N' => $this->copyConflictDialog = null,
                default => null,
            };

            return;
        }
        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Enter => $this->confirmCopy($dlg, $onTick),
                KeyCode::Esc => $this->copyConflictDialog = null,
                default => null,
            };
        }
    }

    private function confirmCopy(CopyConflictDialog $dlg, ?callable $onTick): void
    {
        $this->copyConflictDialog = null;
        $this->performCopy($dlg->sourceSide, $dlg->entries, $dlg->sourceDir, $dlg->destDir, $onTick);
    }

    /** @param list<FileEntry> $entries */
    private function performCopy(PanelSide $sourceSide, array $entries, string $sourceDir, string $destDir, ?callable $onTick): void
    {
        $sourcePanel = $sourceSide === PanelSide::Left ? $this->left : $this->right;
        $destPanel = $sourceSide === PanelSide::Left ? $this->right : $this->left;
        $sourcePanel->clearMarks();

        $totalFiles = max(1, array_sum(array_map(
            fn (FileEntry $e): int => TransferEngine::countLocalFiles(self::joinLocal($sourceDir, $e->name)),
            $entries,
        )));

        $progress = new TransferProgress($totalFiles);
        $this->activeTransfer = $progress;
        $this->activeTransferVerb = 'Copy';

        TransferEngine::copyBatch($entries, $sourceDir, $destDir, $progress, $onTick);

        $this->activeTransfer = null;
        if ($progress->state === TransferState::Done) {
            $this->statusMessage = 'Kopieren abgeschlossen';
            $this->tryRun(fn () => $destPanel->loadLocal());
        } elseif ($progress->state === TransferState::Cancelled) {
            $this->statusMessage = 'Kopieren abgebrochen';
            $this->tryRun(fn () => $destPanel->loadLocal());
        } else {
            $this->statusMessage = 'Kopieren fehlgeschlagen: ' . ($progress->errorMessage ?? 'unbekannter Fehler');
        }
    }

    // -------------------------------------------------------------------
    // Edit (F4)
    // -------------------------------------------------------------------

    /**
     * Prepare an editor launch for the selected file. Local files are opened
     * in place; remote files are downloaded synchronously to a temp dir
     * first, recording the mtime so finishEdit() can tell whether the
     * editor saved anything. The main loop performs the actual terminal
     * suspend and process spawn — same split as Rust's prepare_edit().
     */
    private function prepareEdit(): void
    {
        $panel = $this->activePanel();
        $entry = $panel->entries[$panel->selected] ?? null;
        if ($entry === null || $entry->isDir || $entry->name === '..') {
            $this->statusMessage = 'Kein bearbeitbarer Eintrag ausgewählt';

            return;
        }

        if ($this->active === ActivePanel::Left) {
            $this->pendingEdit = EditRequest::local(self::joinLocal($this->left->path, $entry->name));

            return;
        }

        if ($this->sftp === null) {
            return;
        }

        $remotePath = $this->sftp->joinRemotePath($this->right->path, $entry->name);
        $tempDir = sys_get_temp_dir() . '/vela-edit-' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0700)) {
            $this->statusMessage = 'Temp-Verzeichnis konnte nicht erstellt werden';

            return;
        }

        $tempPath = $tempDir . '/' . $entry->name;
        try {
            $this->sftp->getFile($remotePath, $tempPath, static function (): void {
            });
        } catch (Throwable $e) {
            $this->statusMessage = 'Download für Bearbeitung fehlgeschlagen: ' . $e->getMessage();
            @unlink($tempPath);
            @rmdir($tempDir);

            return;
        }

        $mtime = @filemtime($tempPath) ?: 0;
        $this->pendingEdit = EditRequest::remote($tempPath, $remotePath, $mtime, $tempDir);
    }

    /**
     * Called by the main loop after the editor process has exited. Local:
     * just reload. Remote: mtime comparison decides whether to re-upload —
     * over a fresh session, since the existing one may have timed out while
     * the editor was open. Mirrors finish_edit().
     */
    public function finishEdit(EditRequest $req): void
    {
        if (!$req->isRemote()) {
            $this->tryRun(fn () => $this->left->loadLocal());
            $this->statusMessage = 'Editor geschlossen';

            return;
        }

        $changed = (@filemtime($req->editPath) ?: 0) > $req->mtimeBefore;
        $sftp = $this->sftp;

        if ($changed && $sftp !== null) {
            try {
                $sftp->uploadFileFresh($req->editPath, (string) $req->remotePath);
                $this->statusMessage = "'" . basename((string) $req->remotePath) . "' hochgeladen";
            } catch (Throwable $e) {
                $this->statusMessage = 'Upload fehlgeschlagen: ' . $e->getMessage();
            }
            $this->tryRun(fn () => $this->setRemoteListing($sftp, $sftp->listDir()));
        } elseif (!$changed) {
            $this->statusMessage = 'Keine Änderungen, kein Upload';
        }

        @unlink($req->editPath);
        if ($req->tempDir !== null) {
            @rmdir($req->tempDir);
        }
    }

    // -------------------------------------------------------------------
    // Image Preview (v) — no Rust original, see ImagePreviewDialog's docblock
    // -------------------------------------------------------------------

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];

    private const MAX_PREVIEW_BYTES = 25 * 1024 * 1024;

    // Absolute ceiling regardless of viewport size — keeps ImagePainter's
    // per-frame pixel iteration bounded even on an unusually wide terminal.
    private const PREVIEW_ABSOLUTE_MAX_WIDTH = 400;

    private const PREVIEW_ABSOLUTE_MAX_HEIGHT = 300;

    /**
     * Downloads (if remote) and, when ext-imagick is available, resizes the
     * selected image into a scratch temp dir before handing its path to
     * ImagePreviewDialog. The resize isn't just cosmetic: php-tui's
     * ImagePainter iterates every source pixel on every render frame, so an
     * unresized multi-megapixel photo would visibly freeze the app. Without
     * ext-imagick, php-tui's own ImageRenderer/ImagePainter already show a
     * graceful placeholder, so we skip resizing entirely and pass the
     * original/downloaded path straight through.
     */
    private function openImagePreview(): void
    {
        $panel = $this->activePanel();
        $entry = $panel->entries[$panel->selected] ?? null;
        if ($entry === null || $entry->isDir || $entry->name === '..') {
            $this->statusMessage = 'Kein bearbeitbarer Eintrag ausgewählt';

            return;
        }

        $ext = strtolower(pathinfo($entry->name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            $this->statusMessage = 'Kein bearbeitbarer Eintrag ausgewählt';

            return;
        }

        if ($entry->size !== null && $entry->size > self::MAX_PREVIEW_BYTES) {
            $this->statusMessage = 'Datei zu groß für Vorschau (>25 MB)';

            return;
        }

        $side = $this->active === ActivePanel::Left ? PanelSide::Left : PanelSide::Right;
        $isRemote = $side === PanelSide::Right && $this->sftp !== null;

        $tempDir = null;
        $sourcePath = self::joinLocal($panel->path, $entry->name);

        if ($isRemote) {
            $sftp = $this->sftp;
            $remotePath = $sftp->joinRemotePath($this->right->path, $entry->name);
            $tempDir = sys_get_temp_dir() . '/vela-preview-' . bin2hex(random_bytes(6));
            if (!mkdir($tempDir, 0700)) {
                $this->statusMessage = 'Temp-Verzeichnis konnte nicht erstellt werden';

                return;
            }
            $sourcePath = $tempDir . '/' . $entry->name;
            try {
                $sftp->getFile($remotePath, $sourcePath, static function (): void {
                });
            } catch (Throwable $e) {
                $this->statusMessage = 'Download für Vorschau fehlgeschlagen: ' . $e->getMessage();
                @unlink($sourcePath);
                @rmdir($tempDir);

                return;
            }
        }

        $previewPath = $sourcePath;

        if (class_exists(Imagick::class)) {
            try {
                $image = new Imagick($sourcePath);
                $image->autoOrient();
                $geo = $image->getImageGeometry();
                [$maxWidth, $maxHeight] = $this->previewTargetDimensions();
                $target = ImageScaling::fit($geo['width'], $geo['height'], $maxWidth, $maxHeight);
                $image->resizeImage($target['width'], $target['height'], Imagick::FILTER_LANCZOS, 1, true);

                // Pad out to exactly maxWidth x maxHeight (letterboxed, not
                // stretched): php-tui's Canvas/ImagePainter maps the image's
                // own pixel dimensions onto the render grid by independent
                // linear scaling on each axis with no interpolation, so
                // handing it anything *smaller* than the actual grid
                // resolution leaves regularly-spaced unpainted target
                // columns/rows (the "vertical stripes"/"tiled" bug). Since
                // that mapping also has no notion of aspect ratio (it always
                // stretches to fill whatever resolution it's given), a plain
                // aspect-preserving resize alone would still be smaller than
                // the grid on whichever axis doesn't match the box's aspect
                // ratio. Padding with solid color to the exact target size
                // avoids both the gap bug and unwanted stretching.
                $image->setImageBackgroundColor(new ImagickPixel('black'));
                $offsetX = (int) (($maxWidth - $image->getImageWidth()) / 2);
                $offsetY = (int) (($maxHeight - $image->getImageHeight()) / 2);
                $image->extentImage($maxWidth, $maxHeight, -$offsetX, -$offsetY);

                if ($tempDir === null) {
                    $tempDir = sys_get_temp_dir() . '/vela-preview-' . bin2hex(random_bytes(6));
                    if (!mkdir($tempDir, 0700)) {
                        $this->statusMessage = 'Temp-Verzeichnis konnte nicht erstellt werden';

                        return;
                    }
                }
                $previewPath = $tempDir . '/preview-' . $entry->name;
                $image->writeImage($previewPath);
                $image->clear();
                $image->destroy();
            } catch (Throwable $e) {
                $this->statusMessage = 'Vorschau fehlgeschlagen: ' . $e->getMessage();
                if ($isRemote) {
                    @unlink($sourcePath);
                }
                if ($tempDir !== null) {
                    @rmdir($tempDir);
                }

                return;
            }
        }

        $this->imagePreviewDialog = new ImagePreviewDialog($entry->name, $previewPath, $tempDir);
    }

    /**
     * Mirrors ImagePreviewDialogRenderer's actual layout (CenteredBox(70, 70)
     * -> bordered Block -> a 1-row hint line under the image), so the resize
     * target is never smaller than what the dialog will actually render
     * into — see the ImagePainter upsampling-gap note on $viewportCols.
     * Marker::HalfBlock doubles vertical resolution relative to terminal
     * rows, hence *2 on the height.
     *
     * @return array{0:int,1:int}
     */
    private function previewTargetDimensions(): array
    {
        $dialogWidth = (int) floor($this->viewportCols * 0.7);
        $dialogHeight = (int) floor($this->viewportRows * 0.7);
        $innerWidth = max(1, $dialogWidth - 2);
        $innerHeight = max(1, $dialogHeight - 2 - 1);

        return [
            min(self::PREVIEW_ABSOLUTE_MAX_WIDTH, $innerWidth),
            min(self::PREVIEW_ABSOLUTE_MAX_HEIGHT, $innerHeight * 2),
        ];
    }

    private function handleImagePreviewDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ImagePreviewDialog $dlg): void
    {
        if ($event instanceof CharKeyEvent && ($event->char === 'q' || $event->char === 'Q')) {
            $this->closeImagePreview($dlg);

            return;
        }
        if ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc) {
            $this->closeImagePreview($dlg);
        }
    }

    private function closeImagePreview(ImagePreviewDialog $dlg): void
    {
        $this->imagePreviewDialog = null;
        if ($dlg->tempDir === null) {
            return;
        }
        foreach (glob($dlg->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dlg->tempDir);
    }

    // -------------------------------------------------------------------
    // Rename (F2)
    // -------------------------------------------------------------------

    private function openRenameDialog(): void
    {
        $side = $this->active === ActivePanel::Left ? PanelSide::Left : PanelSide::Right;
        if ($side === PanelSide::Right && $this->sftp === null) {
            return;
        }
        $panel = $this->activePanel();
        $entry = $panel->entries[$panel->selected] ?? null;
        if ($entry === null || $entry->name === '..') {
            return;
        }
        $this->renameDialog = new RenameDialog($side, $entry->name);
    }

    private function handleRenameDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, RenameDialog $dlg): void
    {
        if ($event instanceof CharKeyEvent) {
            $dlg->input->insert($event->char);

            return;
        }
        if (!$event instanceof CodedKeyEvent) {
            return;
        }
        match ($event->code) {
            KeyCode::Esc => $this->renameDialog = null,
            KeyCode::Enter => $this->confirmRename($dlg),
            KeyCode::Left => $dlg->input->moveLeft(),
            KeyCode::Right => $dlg->input->moveRight(),
            KeyCode::Home => $dlg->input->moveHome(),
            KeyCode::End => $dlg->input->moveEnd(),
            KeyCode::Backspace => $dlg->input->backspace(),
            KeyCode::Delete => $dlg->input->deleteForward(),
            default => null,
        };
    }

    private function confirmRename(RenameDialog $dlg): void
    {
        $this->renameDialog = null;
        $newName = trim($dlg->input->value());
        if ($newName === '' || $newName === $dlg->original) {
            return;
        }

        try {
            if ($dlg->side === PanelSide::Left) {
                $old = self::joinLocal($this->left->path, $dlg->original);
                $new = self::joinLocal($this->left->path, $newName);
                if (!rename($old, $new)) {
                    throw new RuntimeException('Umbenennen fehlgeschlagen');
                }
                $this->statusMessage = "Umbenannt: {$dlg->original} → {$newName}";
                $this->left->loadLocal();
            } elseif ($this->sftp !== null) {
                $this->sftp->renameEntry($dlg->original, $newName);
                $this->statusMessage = "Umbenannt: {$dlg->original} → {$newName}";
                $this->setRemoteListing($this->sftp, $this->sftp->listDir());
            }
        } catch (Throwable $e) {
            $this->statusMessage = 'Umbenennen fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------
    // Mkdir (F7)
    // -------------------------------------------------------------------

    private function openMkdirDialog(): void
    {
        $side = $this->active === ActivePanel::Left ? PanelSide::Left : PanelSide::Right;
        if ($side === PanelSide::Right && $this->sftp === null) {
            return;
        }
        $this->mkdirDialog = new MkdirDialog($side);
    }

    private function handleMkdirDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, MkdirDialog $dlg): void
    {
        if ($event instanceof CharKeyEvent) {
            $dlg->input->insert($event->char);

            return;
        }
        if (!$event instanceof CodedKeyEvent) {
            return;
        }
        match ($event->code) {
            KeyCode::Esc => $this->mkdirDialog = null,
            KeyCode::Enter => $this->confirmMkdir($dlg),
            KeyCode::Left => $dlg->input->moveLeft(),
            KeyCode::Right => $dlg->input->moveRight(),
            KeyCode::Home => $dlg->input->moveHome(),
            KeyCode::End => $dlg->input->moveEnd(),
            KeyCode::Backspace => $dlg->input->backspace(),
            KeyCode::Delete => $dlg->input->deleteForward(),
            default => null,
        };
    }

    private function confirmMkdir(MkdirDialog $dlg): void
    {
        $this->mkdirDialog = null;
        $name = trim($dlg->input->value());
        if ($name === '') {
            return;
        }

        try {
            if ($dlg->side === PanelSide::Left) {
                if (!mkdir(self::joinLocal($this->left->path, $name))) {
                    throw new RuntimeException('mkdir fehlgeschlagen');
                }
                $this->statusMessage = "Verzeichnis erstellt: {$name}";
                $this->left->loadLocal();
            } elseif ($this->sftp !== null) {
                $this->sftp->createDirectory($name);
                $this->statusMessage = "Verzeichnis erstellt: {$name}";
                $this->setRemoteListing($this->sftp, $this->sftp->listDir());
            }
        } catch (Throwable $e) {
            $this->statusMessage = 'Erstellen fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------
    // Delete (F8)
    // -------------------------------------------------------------------

    private function openDeleteDialog(): void
    {
        $side = $this->active === ActivePanel::Left ? PanelSide::Left : PanelSide::Right;
        if ($side === PanelSide::Right && $this->sftp === null) {
            return;
        }
        $entries = self::entriesToTransfer($this->activePanel());
        if ($entries === []) {
            return;
        }
        $pairs = array_map(
            static fn (FileEntry $e): array => ['name' => $e->name, 'isDir' => $e->isDir],
            $entries,
        );
        $this->deleteDialog = new DeleteDialog($side, $pairs);
    }

    private function handleDeleteDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, DeleteDialog $dlg): void
    {
        if ($event instanceof CharKeyEvent) {
            match ($event->char) {
                'y', 'Y' => $this->confirmDelete($dlg),
                'n', 'N' => $this->deleteDialog = null,
                default => null,
            };

            return;
        }
        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Enter => $this->confirmDelete($dlg),
                KeyCode::Esc => $this->deleteDialog = null,
                default => null,
            };
        }
    }

    private function confirmDelete(DeleteDialog $dlg): void
    {
        $this->deleteDialog = null;
        $deleted = 0;
        $total = count($dlg->entries);
        $lastError = null;

        foreach ($dlg->entries as $entry) {
            try {
                if ($dlg->side === PanelSide::Left) {
                    $path = self::joinLocal($this->left->path, $entry['name']);
                    if ($entry['isDir']) {
                        self::deleteLocalRecursive($path);
                    } elseif (!unlink($path)) {
                        throw new RuntimeException("Löschen fehlgeschlagen: {$path}");
                    }
                } else {
                    if ($this->sftp === null) {
                        break;
                    }
                    if ($entry['isDir']) {
                        $this->sftp->deleteDirectory($entry['name']);
                    } else {
                        $this->sftp->deleteFile($entry['name']);
                    }
                }
                $deleted++;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($dlg->side === PanelSide::Left) {
            $this->tryRun(fn () => $this->left->loadLocal());
            $this->left->clearMarks();
        } elseif ($this->sftp !== null) {
            try {
                $this->setRemoteListing($this->sftp, $this->sftp->listDir());
            } catch (Throwable $e) {
                $this->statusMessage = 'Listing fehlgeschlagen: ' . $e->getMessage();

                return;
            }
            $this->right->clearMarks();
        }

        if ($lastError !== null) {
            $this->statusMessage = "{$deleted}/{$total} gelöscht — Fehler: {$lastError}";
        } elseif ($total === 1) {
            $this->statusMessage = "'{$dlg->entries[0]['name']}' gelöscht";
        } else {
            $this->statusMessage = "{$deleted} Einträge gelöscht";
        }
    }

    // -------------------------------------------------------------------
    // Profile dialog (F9 / p)
    // -------------------------------------------------------------------

    private function openProfileDialog(): void
    {
        try {
            $store = ProfileStore::load();
        } catch (Throwable $e) {
            $this->statusMessage = $e->getMessage();

            return;
        }
        $this->profileDialog = new ProfileDialog($store);
    }

    private function handleProfileDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ProfileDialog $dlg): void
    {
        match ($dlg->mode) {
            ProfileDialogMode::ListMode => $this->handleProfileListKey($event, $dlg),
            ProfileDialogMode::New => $this->handleProfileFormKey($event, $dlg, isEdit: false),
            ProfileDialogMode::Edit => $this->handleProfileFormKey($event, $dlg, isEdit: true),
            ProfileDialogMode::ConfirmDelete => $this->handleProfileConfirmDeleteKey($event, $dlg),
        };
    }

    private function handleProfileListKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ProfileDialog $dlg): void
    {
        if ($event instanceof CharKeyEvent) {
            match ($event->char) {
                'n', 'N' => $this->profileOpenNewForm($dlg),
                'e', 'E' => $this->profileEditSelected($dlg),
                'd', 'D' => $this->profileDeleteSelectedPrompt($dlg),
                default => null,
            };

            return;
        }
        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Esc => $this->profileDialog = null,
                KeyCode::Up => $dlg->listMoveUp(),
                KeyCode::Down => $dlg->listMoveDown(),
                KeyCode::Enter => $this->profileConnectSelected($dlg),
                KeyCode::Delete => $this->profileDeleteSelectedPrompt($dlg),
                default => null,
            };

            return;
        }
        // At this point $event can only be a FunctionKeyEvent — the other
        // two members of the union already returned above.
        if ($event->number === 2) {
            $this->profileEditSelected($dlg);
        }
    }

    private function profileOpenNewForm(ProfileDialog $dlg): void
    {
        $dlg->mode = ProfileDialogMode::New;
        $dlg->form = new NewProfileForm();
        $dlg->field = 0;
    }

    private function profileEditSelected(ProfileDialog $dlg): void
    {
        if ($dlg->store->profiles === []) {
            return;
        }
        $index = $dlg->listSelected;
        $dlg->form = NewProfileForm::fromProfile($dlg->store->profiles[$index]);
        $dlg->editIndex = $index;
        $dlg->field = 0;
        $dlg->mode = ProfileDialogMode::Edit;
    }

    private function profileDeleteSelectedPrompt(ProfileDialog $dlg): void
    {
        if ($dlg->store->profiles === []) {
            return;
        }
        $dlg->deleteIndex = $dlg->listSelected;
        $dlg->mode = ProfileDialogMode::ConfirmDelete;
    }

    private function profileConnectSelected(ProfileDialog $dlg): void
    {
        if ($dlg->store->profiles === []) {
            return;
        }
        $profile = $dlg->store->profiles[$dlg->listSelected];
        $this->profileDialog = null;
        $this->beginConnect($profile);
    }

    private function handleProfileFormKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ProfileDialog $dlg, bool $isEdit): void
    {
        $form = $dlg->form;

        if ($event instanceof CodedKeyEvent) {
            match ($event->code) {
                KeyCode::Esc => $dlg->mode = ProfileDialogMode::ListMode,
                KeyCode::Tab => $dlg->field = $form->nextField($dlg->field),
                KeyCode::BackTab => $dlg->field = $form->prevField($dlg->field),
                KeyCode::Enter => $isEdit ? $this->saveEditedProfile($dlg, $dlg->editIndex) : $this->saveNewProfile($dlg),
                KeyCode::Backspace => $this->profileFieldBackspace($form, $dlg->field),
                default => null,
            };

            return;
        }

        if (!$event instanceof CharKeyEvent) {
            return;
        }

        if ($dlg->field === NewProfileForm::AUTH) {
            if ($event->char === ' ') {
                $form->auth = $form->auth === AuthMethod::Key ? AuthMethod::Password : AuthMethod::Key;
            }

            return;
        }

        if ($dlg->field === NewProfileForm::SAVE_PASSWORD) {
            if ($event->char === ' ') {
                $form->savePassword = !$form->savePassword;
            }

            return;
        }

        if ($dlg->field === NewProfileForm::PORT && !ctype_digit($event->char)) {
            return;
        }

        $current = $form->fieldValue($dlg->field) ?? '';
        $form->setFieldValue($dlg->field, $current . $event->char);
    }

    private function profileFieldBackspace(NewProfileForm $form, int $field): void
    {
        if ($field === NewProfileForm::AUTH || $field === NewProfileForm::SAVE_PASSWORD) {
            return;
        }
        $current = $form->fieldValue($field) ?? '';
        if ($current !== '') {
            $form->setFieldValue($field, mb_substr($current, 0, -1));
        }
    }

    private function saveNewProfile(ProfileDialog $dlg): void
    {
        $profile = $dlg->form->toProfile();
        if ($profile === null) {
            $this->statusMessage = 'Name, Host und User dürfen nicht leer sein';

            return;
        }

        [$profile, $keychainNote] = $this->applyKeychainSave($profile, $dlg->form, originalHadSaved: false);

        $dlg->store->add($profile);
        $this->persistProfileStore($dlg->store, "Profil '{$profile->name}' gespeichert{$keychainNote}");
        $dlg->mode = ProfileDialogMode::ListMode;
    }

    private function saveEditedProfile(ProfileDialog $dlg, ?int $index): void
    {
        if ($index === null) {
            return;
        }
        $profile = $dlg->form->toProfile();
        if ($profile === null) {
            $this->statusMessage = 'Name, Host und User dürfen nicht leer sein';

            return;
        }

        $originalHadSaved = isset($dlg->store->profiles[$index]) && $dlg->store->profiles[$index]->hasSavedPassword;
        [$profile, $keychainNote] = $this->applyKeychainSave($profile, $dlg->form, $originalHadSaved);

        $dlg->store->update($index, $profile);
        $this->persistProfileStore($dlg->store, "Profil '{$profile->name}' aktualisiert{$keychainNote}");
        $dlg->mode = ProfileDialogMode::ListMode;
    }

    /**
     * Keychain side of a profile save, mirroring save_new_profile()/
     * save_edited_profile(): store the typed password when the toggle is on,
     * delete the entry when it was turned off, keep the existing entry
     * untouched when the toggle stays on but no new password was typed.
     *
     * @return array{Profile,string} the profile with its real hasSavedPassword, plus a status suffix
     */
    private function applyKeychainSave(Profile $profile, NewProfileForm $form, bool $originalHadSaved): array
    {
        $wantsSave = $form->savePassword && $form->password !== '';
        $wantsDelete = !$form->savePassword;

        if ($wantsSave) {
            try {
                Keychain::savePassword($profile->name, $form->password);

                return [self::withSavedPassword($profile, true), ' — Passwort im Keychain gespeichert'];
            } catch (Throwable $e) {
                return [self::withSavedPassword($profile, false), ' — Keychain-Fehler: ' . $e->getMessage()];
            }
        }

        if ($wantsDelete) {
            Keychain::deletePassword($profile->name);

            return [self::withSavedPassword($profile, false), ''];
        }

        // Toggle on, but no new password typed: leave the keychain alone.
        return [self::withSavedPassword($profile, $originalHadSaved), ''];
    }

    private static function withSavedPassword(Profile $p, bool $hasSavedPassword): Profile
    {
        return new Profile(
            name: $p->name,
            host: $p->host,
            port: $p->port,
            user: $p->user,
            auth: $p->auth,
            keyPath: $p->keyPath,
            remotePath: $p->remotePath,
            localStartPath: $p->localStartPath,
            hasSavedPassword: $hasSavedPassword,
        );
    }

    private function persistProfileStore(ProfileStore $store, string $successMessage): void
    {
        try {
            $store->save();
            $this->statusMessage = $successMessage;
        } catch (Throwable $e) {
            $this->statusMessage = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }

    private function handleProfileConfirmDeleteKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ProfileDialog $dlg): void
    {
        $confirm = ($event instanceof CharKeyEvent && in_array($event->char, ['y', 'Y'], true))
            || ($event instanceof CodedKeyEvent && $event->code === KeyCode::Enter);
        $cancel = ($event instanceof CharKeyEvent && in_array($event->char, ['n', 'N'], true))
            || ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc);

        if ($confirm && $dlg->deleteIndex !== null) {
            if (isset($dlg->store->profiles[$dlg->deleteIndex])) {
                // Best-effort, mirrors delete_password() being ignored in Rust.
                Keychain::deletePassword($dlg->store->profiles[$dlg->deleteIndex]->name);
            }
            $dlg->store->remove($dlg->deleteIndex);
            $dlg->listSelected = min($dlg->listSelected, max(count($dlg->store->profiles) - 1, 0));
            $this->persistProfileStore($dlg->store, 'Profil gelöscht');
            $dlg->mode = ProfileDialogMode::ListMode;
        } elseif ($cancel) {
            $dlg->mode = ProfileDialogMode::ListMode;
        }
    }

    // -------------------------------------------------------------------
    // Connect flow: password dialog + host-key dialog + doConnect
    // -------------------------------------------------------------------

    private function beginConnect(Profile $profile): void
    {
        if ($profile->auth === AuthMethod::Password) {
            // Saved-password fast path (mirrors begin_connect): only prompt
            // when the keychain has nothing for this profile.
            if ($profile->hasSavedPassword) {
                $saved = Keychain::loadPassword($profile->name);
                if ($saved !== null) {
                    $this->doConnect($profile, $saved);

                    return;
                }
            }
            $this->passwordDialog = new PasswordDialog($profile);

            return;
        }
        $this->doConnect($profile, null);
    }

    private function doConnect(Profile $profile, ?string $password): void
    {
        try {
            $sftp = SftpConnection::connect($profile, $password);
        } catch (UnknownHostKeyException $e) {
            $this->hostKeyDialog = new HostKeyDialog($e->host, $e->port, $e->fingerprint, $e->keyType, $e->keyBytes, $profile, $password);

            return;
        } catch (Throwable $e) {
            if ($this->passwordDialog !== null) {
                $this->passwordDialog->error = $e->getMessage();
            } else {
                $this->statusMessage = $e->getMessage();
            }

            return;
        }

        $this->sftp = $sftp;

        $remotePath = $profile->remotePath !== null ? trim($profile->remotePath) : '';
        $statusExtra = '';
        try {
            if ($remotePath !== '') {
                $entries = $sftp->changeToAbsolute($remotePath);
                $statusExtra = " → {$sftp->remotePath}";
            } else {
                $entries = $sftp->listDir();
            }
        } catch (Throwable $e) {
            $entries = $sftp->listDir();
            $statusExtra = " | Start-Verzeichnis '{$remotePath}' nicht erreichbar: " . $e->getMessage();
        }

        $this->right->path = $sftp->remotePath;
        $this->right->entries = $entries;
        $this->right->selected = 0;
        $this->right->marked = [];

        $this->statusMessage = "Verbunden: {$profile->user}@{$profile->host}{$statusExtra}";
        $this->passwordDialog = null;

        if ($profile->localStartPath !== null && trim($profile->localStartPath) !== '') {
            $expanded = self::expandTildeLocal(trim($profile->localStartPath));
            if (is_dir($expanded)) {
                $this->left->path = $expanded;
                try {
                    $this->left->loadLocal();
                } catch (Throwable $e) {
                    $this->statusMessage .= ' | Lok. Startpfad fehlgeschlagen: ' . $e->getMessage();
                }
            }
        }
    }

    private function disconnectSftp(): void
    {
        if ($this->sftp === null) {
            return;
        }
        $this->sftp = null;
        $homeEnv = $_SERVER['HOME'] ?? getenv('HOME');
        $home = is_string($homeEnv) && $homeEnv !== '' ? $homeEnv : (getcwd() ?: '/');
        $this->right = new PanelState($home);
        $this->tryRun(fn () => $this->right->loadLocal());
        $this->statusMessage = 'Getrennt';
    }

    private function handlePasswordDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, PasswordDialog $dlg): void
    {
        if ($event instanceof CharKeyEvent) {
            $dlg->input->insert($event->char);
            $dlg->error = null;

            return;
        }
        if (!$event instanceof CodedKeyEvent) {
            return;
        }
        if ($event->code === KeyCode::Esc) {
            $this->passwordDialog = null;
            $this->statusMessage = 'Verbindung abgebrochen';
        } elseif ($event->code === KeyCode::Enter) {
            $this->doConnect($dlg->profile, $dlg->input->value());
        } elseif ($event->code === KeyCode::Backspace) {
            $dlg->input->backspace();
            $dlg->error = null;
        }
    }

    private function handleHostKeyDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, HostKeyDialog $dlg): void
    {
        $accept = ($event instanceof CharKeyEvent && in_array($event->char, ['y', 'Y'], true))
            || ($event instanceof CodedKeyEvent && $event->code === KeyCode::Enter);
        $reject = ($event instanceof CharKeyEvent && in_array($event->char, ['n', 'N'], true))
            || ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc);

        if ($accept) {
            $this->hostKeyDialog = null;
            try {
                SftpConnection::addToKnownHosts($dlg->host, $dlg->port, $dlg->keyType, $dlg->keyBytes);
                $this->doConnect($dlg->profile, $dlg->password);
            } catch (Throwable $e) {
                $this->statusMessage = 'known_hosts schreiben fehlgeschlagen: ' . $e->getMessage();
            }
        } elseif ($reject) {
            $this->hostKeyDialog = null;
            $this->statusMessage = 'Verbindung abgebrochen (unbekannter Host-Key)';
        }
    }

    // -------------------------------------------------------------------
    // Permission-fix dialog (profiles.toml not mode 0600)
    // -------------------------------------------------------------------

    private function handlePermissionDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, PermissionFixDialog $dlg): void
    {
        $fix = $event instanceof CharKeyEvent && in_array($event->char, ['f', 'F'], true);
        $dismiss = ($event instanceof CharKeyEvent && in_array($event->char, ['i', 'I'], true))
            || ($event instanceof CodedKeyEvent && $event->code === KeyCode::Esc);

        if ($fix) {
            @chmod($dlg->path, 0600);
            $this->permissionDialog = null;
        } elseif ($dismiss) {
            $this->permissionDialog = null;
        }
    }

    // -------------------------------------------------------------------
    // Shell (!) and tail (t)
    // -------------------------------------------------------------------

    private function openShellDialog(): void
    {
        $this->shellDialog = new ShellDialog();
    }

    private function openTailDialog(): void
    {
        if ($this->active !== ActivePanel::Right) {
            $this->statusMessage = 'Tail nur für Remote-Dateien (rechtes Panel)';

            return;
        }
        if ($this->sftp === null) {
            $this->statusMessage = 'Nicht verbunden';

            return;
        }
        $entry = $this->right->entries[$this->right->selected] ?? null;
        if ($entry === null || $entry->isDir || $entry->name === '..') {
            $this->statusMessage = 'Keine Datei ausgewählt';

            return;
        }

        $dlg = new ShellDialog();
        $remotePath = $this->sftp->joinRemotePath($this->right->path, $entry->name);
        try {
            $dlg->output = $this->sftp->tailRemoteFile($remotePath, 50);
            $dlg->exitCode = 0;
            $this->statusMessage = "Tail – {$entry->name}";
        } catch (Throwable $e) {
            $dlg->output = ['Fehler: ' . $e->getMessage()];
            $dlg->exitCode = 1;
            $this->statusMessage = 'Tail fehlgeschlagen';
        }
        $this->shellDialog = $dlg;
    }

    private function handleShellDialogKey(CharKeyEvent|CodedKeyEvent|FunctionKeyEvent $event, ShellDialog $dlg): void
    {
        if ($dlg->output !== null) {
            if ($event instanceof CharKeyEvent && $event->char === 'q') {
                $this->shellDialog = null;

                return;
            }
            if (!$event instanceof CodedKeyEvent) {
                return;
            }
            $total = count($dlg->output);
            match ($event->code) {
                KeyCode::Esc => $this->shellDialog = null,
                KeyCode::Up => $dlg->scrollUp(),
                KeyCode::Down => $dlg->scrollDown($total),
                KeyCode::PageUp => $dlg->pageUp(),
                KeyCode::PageDown => $dlg->pageDown($total),
                default => null,
            };

            return;
        }

        if ($event instanceof CharKeyEvent) {
            $dlg->input->insert($event->char);

            return;
        }
        if (!$event instanceof CodedKeyEvent) {
            return;
        }
        match ($event->code) {
            KeyCode::Esc => $this->shellDialog = null,
            KeyCode::Enter => $this->runShellCommand($dlg),
            KeyCode::Left => $dlg->input->moveLeft(),
            KeyCode::Right => $dlg->input->moveRight(),
            KeyCode::Home => $dlg->input->moveHome(),
            KeyCode::End => $dlg->input->moveEnd(),
            KeyCode::Backspace => $dlg->input->backspace(),
            KeyCode::Delete => $dlg->input->deleteForward(),
            default => null,
        };
    }

    private function runShellCommand(ShellDialog $dlg): void
    {
        $cmd = trim($dlg->input->value());
        if ($cmd === '') {
            $this->shellDialog = null;

            return;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $this->left->path);
        if (!is_resource($process)) {
            $dlg->output = ['Fehler: Befehl konnte nicht gestartet werden'];
            $dlg->exitCode = 1;
        } else {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $combined = trim($stdout . $stderr);
            $dlg->output = $combined === '' ? ['(keine Ausgabe)'] : explode("\n", $combined);
            $dlg->exitCode = $exitCode;
        }
        $dlg->scroll = 0;
        $this->tryRun(fn () => $this->left->loadLocal());
        $this->statusMessage = "! {$cmd} — Exit {$dlg->exitCode}";
    }

    // -------------------------------------------------------------------
    // Theme (Ctrl+T)
    // -------------------------------------------------------------------

    private function cycleTheme(): void
    {
        $customs = ThemeStore::customThemeNames();
        $this->themeChoice = self::nextTheme($this->themeChoice, $customs);
        ThemeStore::saveThemeChoice($this->themeChoice);
        $this->statusMessage = "Theme: {$this->themeChoice->label()}";
    }

    /** @param string[] $customs */
    private static function nextTheme(ThemeChoice $current, array $customs): ThemeChoice
    {
        $list = [ThemeChoice::auto(), ThemeChoice::dark(), ThemeChoice::light()];
        foreach ($customs as $name) {
            $list[] = ThemeChoice::custom($name);
        }
        foreach ($list as $i => $choice) {
            if ($choice->equals($current)) {
                return $list[($i + 1) % count($list)];
            }
        }

        return ThemeChoice::auto();
    }

    // -------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------

    /** @return list<FileEntry> */
    private static function entriesToTransfer(PanelState $panel): array
    {
        if ($panel->marked === []) {
            $entry = $panel->entries[$panel->selected] ?? null;

            return $entry !== null && $entry->name !== '..' ? [$entry] : [];
        }

        $indices = array_keys($panel->marked);
        sort($indices);

        $entries = [];
        foreach ($indices as $i) {
            $entry = $panel->entries[$i] ?? null;
            if ($entry !== null && $entry->name !== '..') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @param list<FileEntry> $entries */
    private function setRemoteListing(SftpConnection $sftp, array $entries): void
    {
        $this->right->path = $sftp->remotePath;
        $this->right->entries = $entries;
        $this->right->selected = 0;
        $this->right->marked = [];
    }

    private static function deleteLocalRecursive(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $names = @scandir($path) ?: [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                self::deleteLocalRecursive(self::joinLocal($path, $name));
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    private static function joinLocal(string $base, string $name): string
    {
        return $base === '/' ? '/' . $name : rtrim($base, '/') . '/' . $name;
    }

    private static function expandTildeLocal(string $path): string
    {
        $homeEnv = $_SERVER['HOME'] ?? getenv('HOME');
        $home = is_string($homeEnv) && $homeEnv !== '' ? $homeEnv : '.';
        if ($path === '~') {
            return $home;
        }
        if (str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }

        return $path;
    }

    private function tryRun(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->statusMessage = $e->getMessage();
        }
    }
}
