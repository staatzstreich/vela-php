<?php

declare(strict_types=1);

namespace Vela\Tests\Transfer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Transfer\TransferEngine;

/**
 * Converts milestone 5's ad-hoc scratch verification into a checked-in
 * test — but only for countLocalFiles(). uploadBatch()/downloadBatch()/
 * countRemoteFiles() all require a real Vela\Connection\SftpConnection,
 * which is `final` with a private constructor (only buildable via
 * connect(), which does real network I/O) — there's no fake/mock to hand
 * them here without either a live server or restructuring SftpConnection
 * behind an interface. That's consistent with how this project has
 * treated anything SFTP-shaped throughout: covered by live, manual pty
 * testing (see the README's milestone notes), not automated unit tests.
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
            is_dir($path) ? self::removeDirectoryRecursively($path) : unlink($path);
        }
        rmdir($dir);
    }
}
