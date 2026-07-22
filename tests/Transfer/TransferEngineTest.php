<?php

declare(strict_types=1);

namespace Vela\Tests\Transfer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Fs\FileEntry;
use Vela\Transfer\TransferEngine;
use Vela\Transfer\TransferProgress;
use Vela\Transfer\TransferState;

/**
 * Converts milestone 5's ad-hoc scratch verification into a checked-in
 * test — but only for countLocalFiles(), uploadBatch()/downloadBatch()/
 * countRemoteFiles() all require a real Vela\Connection\SftpConnection,
 * which is `final` with a private constructor (only buildable via
 * connect(), which does real network I/O) — there's no fake/mock to hand
 * them here without either a live server or restructuring SftpConnection
 * behind an interface. That's consistent with how this project has
 * treated anything SFTP-shaped throughout: covered by live, manual pty
 * testing (see the README's milestone notes), not automated unit tests.
 *
 * copyBatch()/findConflicts() are the exception: local-to-local copy has
 * no SFTP dependency at all, so it's fully unit-testable, unlike its
 * upload/download siblings above. Destination-disk-full is deliberately
 * not tested: there's no portable, non-flaky way to force ENOSPC here,
 * and copy()'s false-return on such a failure already routes through the
 * same RuntimeException -> Failed path every failure test below exercises.
 * Also not addressed here (pre-existing, not something this feature
 * changes): countLocalFiles() follows symlinks via scandir()/is_file()
 * without cycle protection, same exposure uploadActive() already has today.
 */
final class TransferEngineTest extends TestCase
{
    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/vela-php-test-' . bin2hex(random_bytes(8));
        mkdir($this->scratchDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectoryRecursively($this->scratchDir);
    }

    #[Test]
    public function countsASingleFilePassedDirectly(): void
    {
        file_put_contents("{$this->scratchDir}/a.txt", 'x');

        self::assertSame(1, TransferEngine::countLocalFiles("{$this->scratchDir}/a.txt"));
    }

    #[Test]
    public function countsAllFilesInAFlatDirectory(): void
    {
        file_put_contents("{$this->scratchDir}/a.txt", 'x');
        file_put_contents("{$this->scratchDir}/b.txt", 'x');
        file_put_contents("{$this->scratchDir}/c.txt", 'x');

        self::assertSame(3, TransferEngine::countLocalFiles($this->scratchDir));
    }

    #[Test]
    public function countsFilesRecursivelyAcrossNestedDirectories(): void
    {
        mkdir("{$this->scratchDir}/sub");
        mkdir("{$this->scratchDir}/sub/deeper");
        file_put_contents("{$this->scratchDir}/top.txt", 'x');
        file_put_contents("{$this->scratchDir}/sub/mid.txt", 'x');
        file_put_contents("{$this->scratchDir}/sub/deeper/bottom.txt", 'x');

        self::assertSame(3, TransferEngine::countLocalFiles($this->scratchDir));
    }

    #[Test]
    public function anEmptyDirectoryContributesZeroFilesButIsNotIgnored(): void
    {
        // A dir with only an empty subdirectory and no files anywhere
        // should count 0 — the recursion must not, say, count the empty
        // subdirectory itself as "a file".
        mkdir("{$this->scratchDir}/empty-sub");

        self::assertSame(0, TransferEngine::countLocalFiles($this->scratchDir));
    }

    #[Test]
    public function returnsZeroForAPathThatDoesNotExist(): void
    {
        self::assertSame(0, TransferEngine::countLocalFiles("{$this->scratchDir}/does-not-exist"));
    }

    #[Test]
    public function copyBatchCopiesASingleFile(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/a.txt", 'hello');

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('a.txt', 5, null, false)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame(1, $progress->filesDone);
        self::assertSame('hello', file_get_contents("{$this->scratchDir}/dest/a.txt"));
    }

    #[Test]
    public function copyBatchCopiesADirectoryRecursively(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        mkdir("{$this->scratchDir}/src/sub");
        mkdir("{$this->scratchDir}/src/sub/deeper");
        file_put_contents("{$this->scratchDir}/src/sub/mid.txt", 'mid');
        file_put_contents("{$this->scratchDir}/src/sub/deeper/bottom.txt", 'bottom');

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('sub', null, null, true)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame('mid', file_get_contents("{$this->scratchDir}/dest/sub/mid.txt"));
        self::assertSame('bottom', file_get_contents("{$this->scratchDir}/dest/sub/deeper/bottom.txt"));
    }

    #[Test]
    public function copyBatchCopiesAMixOfFilesAndDirectoriesInOneBatch(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        mkdir("{$this->scratchDir}/src/sub");
        file_put_contents("{$this->scratchDir}/src/sub/nested.txt", 'deep');
        file_put_contents("{$this->scratchDir}/src/a.txt", 'a');
        file_put_contents("{$this->scratchDir}/src/b.txt", 'b');

        $progress = new TransferProgress(3);
        TransferEngine::copyBatch(
            [
                new FileEntry('a.txt', 1, null, false),
                new FileEntry('b.txt', 1, null, false),
                new FileEntry('sub', null, null, true),
            ],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame('a', file_get_contents("{$this->scratchDir}/dest/a.txt"));
        self::assertSame('b', file_get_contents("{$this->scratchDir}/dest/b.txt"));
        self::assertSame('deep', file_get_contents("{$this->scratchDir}/dest/sub/nested.txt"));
    }

    #[Test]
    public function copyBatchOverwritesAnExistingFileAtDestination(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/a.txt", 'new content');
        file_put_contents("{$this->scratchDir}/dest/a.txt", 'old content');

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('a.txt', 11, null, false)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame('new content', file_get_contents("{$this->scratchDir}/dest/a.txt"));
    }

    #[Test]
    public function copyBatchMergesIntoAnExistingDestinationDirectory(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        mkdir("{$this->scratchDir}/src/sub");
        mkdir("{$this->scratchDir}/dest/sub");
        file_put_contents("{$this->scratchDir}/src/sub/shared.txt", 'updated');
        file_put_contents("{$this->scratchDir}/src/sub/new.txt", 'new');
        file_put_contents("{$this->scratchDir}/dest/sub/shared.txt", 'original');
        file_put_contents("{$this->scratchDir}/dest/sub/untouched.txt", 'keep me');

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('sub', null, null, true)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame('updated', file_get_contents("{$this->scratchDir}/dest/sub/shared.txt"));
        self::assertSame('new', file_get_contents("{$this->scratchDir}/dest/sub/new.txt"));
        self::assertSame('keep me', file_get_contents("{$this->scratchDir}/dest/sub/untouched.txt"));
    }

    #[Test]
    public function copyBatchCopiesASymlinkToAFileAsAFreshSymlink(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/real.txt", 'target content');
        symlink("{$this->scratchDir}/src/real.txt", "{$this->scratchDir}/src/link.txt");

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('link.txt', null, null, false)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertTrue(is_link("{$this->scratchDir}/dest/link.txt"));
        self::assertSame(
            readlink("{$this->scratchDir}/src/link.txt"),
            readlink("{$this->scratchDir}/dest/link.txt"),
        );
    }

    #[Test]
    public function copyBatchCopiesASymlinkToADirectoryWithoutFollowingIt(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        mkdir("{$this->scratchDir}/src/realdir");
        file_put_contents("{$this->scratchDir}/src/realdir/inside.txt", 'x');
        symlink("{$this->scratchDir}/src/realdir", "{$this->scratchDir}/src/linkdir");

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('linkdir', null, null, true)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertTrue(is_link("{$this->scratchDir}/dest/linkdir"));
        self::assertSame(
            readlink("{$this->scratchDir}/src/linkdir"),
            readlink("{$this->scratchDir}/dest/linkdir"),
        );
    }

    #[Test]
    public function copyBatchFailsWhenSourceAndDestinationAreTheSameDirectory(): void
    {
        mkdir("{$this->scratchDir}/src");
        file_put_contents("{$this->scratchDir}/src/a.txt", 'x');

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('a.txt', 1, null, false)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/src",
            $progress,
        );

        self::assertSame(TransferState::Failed, $progress->state);
        self::assertNotNull($progress->errorMessage);
    }

    #[Test]
    public function copyBatchFailsWhenDestinationIsNestedInsideTheSourceEntry(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/src/a");
        mkdir("{$this->scratchDir}/src/a/sub");

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('a', null, null, true)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/src/a/sub",
            $progress,
        );

        self::assertSame(TransferState::Failed, $progress->state);
        self::assertNotNull($progress->errorMessage);
    }

    #[Test]
    public function copyBatchFailsWhenAFileWouldOverwriteADirectoryOfTheSameName(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/name.txt", 'x');
        mkdir("{$this->scratchDir}/dest/name.txt");

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('name.txt', 1, null, false)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Failed, $progress->state);
    }

    #[Test]
    public function copyBatchFailsWhenADirectoryWouldOverwriteAFileOfTheSameName(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        mkdir("{$this->scratchDir}/src/name");
        file_put_contents("{$this->scratchDir}/dest/name", 'x');

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('name', null, null, true)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Failed, $progress->state);
    }

    #[Test]
    public function copyBatchAbortsAfterTheFirstErrorButKeepsEarlierEntries(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/a.txt", 'first');
        mkdir("{$this->scratchDir}/src/b");
        file_put_contents("{$this->scratchDir}/dest/b", 'blocks the dir with a file of the same name');
        file_put_contents("{$this->scratchDir}/src/c.txt", 'third');

        $progress = new TransferProgress(3);
        TransferEngine::copyBatch(
            [
                new FileEntry('a.txt', 5, null, false),
                new FileEntry('b', null, null, true),
                new FileEntry('c.txt', 5, null, false),
            ],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Failed, $progress->state);
        self::assertSame('first', file_get_contents("{$this->scratchDir}/dest/a.txt"));
        self::assertFileDoesNotExist("{$this->scratchDir}/dest/c.txt");
    }

    #[Test]
    public function copyBatchReportsProgressPerFileViaOnTick(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/a.txt", 'x');
        file_put_contents("{$this->scratchDir}/src/b.txt", 'y');

        $progress = new TransferProgress(2);
        $tickCount = 0;
        TransferEngine::copyBatch(
            [
                new FileEntry('a.txt', 1, null, false),
                new FileEntry('b.txt', 1, null, false),
            ],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
            static function () use (&$tickCount): void {
                $tickCount++;
            },
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame(2, $progress->filesDone);
        self::assertGreaterThan(0, $tickCount);
    }

    #[Test]
    public function copyBatchCopiesAReadOnlySourceFile(): void
    {
        mkdir("{$this->scratchDir}/src");
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/src/readonly.txt", 'protected content');
        chmod("{$this->scratchDir}/src/readonly.txt", 0444);

        $progress = new TransferProgress(1);
        TransferEngine::copyBatch(
            [new FileEntry('readonly.txt', 17, null, false)],
            "{$this->scratchDir}/src",
            "{$this->scratchDir}/dest",
            $progress,
        );

        self::assertSame(TransferState::Done, $progress->state);
        self::assertSame('protected content', file_get_contents("{$this->scratchDir}/dest/readonly.txt"));
    }

    #[Test]
    public function findConflictsReturnsEmptyWhenNothingCollides(): void
    {
        mkdir("{$this->scratchDir}/dest");

        self::assertSame(
            [],
            TransferEngine::findConflicts([new FileEntry('new.txt', 1, null, false)], "{$this->scratchDir}/dest"),
        );
    }

    #[Test]
    public function findConflictsReturnsExactlyTheCollidingNames(): void
    {
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/dest/existing.txt", 'x');
        mkdir("{$this->scratchDir}/dest/existingdir");

        $conflicts = TransferEngine::findConflicts(
            [
                new FileEntry('existing.txt', 1, null, false),
                new FileEntry('existingdir', null, null, true),
                new FileEntry('new.txt', 1, null, false),
            ],
            "{$this->scratchDir}/dest",
        );

        self::assertSame(['existing.txt', 'existingdir'], $conflicts);
    }

    #[Test]
    public function findConflictsDoesNotFalsePositiveOnPartialNameMatches(): void
    {
        mkdir("{$this->scratchDir}/dest");
        file_put_contents("{$this->scratchDir}/dest/report.txt", 'x');

        self::assertSame(
            [],
            TransferEngine::findConflicts([new FileEntry('report.txt.bak', 1, null, false)], "{$this->scratchDir}/dest"),
        );
    }

    private static function removeDirectoryRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::removeDirectoryRecursively($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
