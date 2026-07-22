<?php

declare(strict_types=1);

namespace Vela\Tests\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Config\AuthMethod;
use Vela\Config\HomeDirNotFoundException;
use Vela\Config\Profile;
use Vela\Config\ProfileStore;
use Vela\Config\UnsafePermissionsException;

/**
 * Converts milestone 2's ad-hoc scratch verification into a checked-in
 * test. ProfileStore reads/writes real files under $_SERVER['HOME'], so
 * every test here points HOME at a throwaway temp directory first (setUp)
 * and removes it afterwards (tearDown) — the real ~/.config/vela/
 * profiles.toml on this machine is never touched.
 */
final class ProfileStoreTest extends TestCase
{
    private string $scratchHome;

    private ?string $originalHome;

    /**
     * Runs before *every* test method in this class (not once for the
     * whole class) — each test gets its own fresh scratch directory, so
     * tests can't accidentally see leftover state from one another.
     */
    protected function setUp(): void
    {
        $this->scratchHome = sys_get_temp_dir() . '/vela-php-test-' . bin2hex(random_bytes(8));
        mkdir($this->scratchHome, 0755, true);

        $this->originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->scratchHome;
    }

    /** Runs after every test, even if it failed — this is what makes tearDown safe to rely on for cleanup. */
    protected function tearDown(): void
    {
        if ($this->originalHome !== null) {
            $_SERVER['HOME'] = $this->originalHome;
        } else {
            unset($_SERVER['HOME']);
        }

        self::removeDirectoryRecursively($this->scratchHome);
    }

    #[Test]
    public function loadReturnsAnEmptyStoreWhenNoFileExists(): void
    {
        $store = ProfileStore::load();

        self::assertSame([], $store->profiles);
    }

    #[Test]
    public function saveThenLoadRoundTripsAllProfileFields(): void
    {
        $store = new ProfileStore();
        $store->add(new Profile(
            name: 'prod',
            host: 'example.com',
            port: 22,
            user: 'deploy',
            auth: AuthMethod::Key,
            keyPath: '~/.ssh/id_rsa',
            remotePath: '/var/www',
        ));
        $store->save();

        $reloaded = ProfileStore::load();

        self::assertCount(1, $reloaded->profiles);
        $p = $reloaded->profiles[0];
        self::assertSame('prod', $p->name);
        self::assertSame('example.com', $p->host);
        self::assertSame(22, $p->port);
        self::assertSame('deploy', $p->user);
        self::assertSame(AuthMethod::Key, $p->auth);
        self::assertSame('~/.ssh/id_rsa', $p->keyPath);
        self::assertSame('/var/www', $p->remotePath);
    }

    #[Test]
    public function saveEnforcesReadWriteOnlyForOwnerPermissions(): void
    {
        $store = new ProfileStore();
        $store->add(new Profile('t', 'h', 22, 'u', AuthMethod::Key));
        $store->save();

        $mode = fileperms(ProfileStore::configPath()) & 0o777;

        self::assertSame(0o600, $mode);
    }

    #[Test]
    public function loadRejectsAFileWithLooserPermissions(): void
    {
        $store = new ProfileStore();
        $store->add(new Profile('t', 'h', 22, 'u', AuthMethod::Key));
        $store->save();
        chmod(ProfileStore::configPath(), 0o644);

        $this->expectException(UnsafePermissionsException::class);

        ProfileStore::load();
    }

    #[Test]
    public function emptyOptionalFieldsAreOmittedFromTheSavedFileButRoundTripAsNull(): void
    {
        // remotePath/localStartPath as *empty strings* (not null) exercise
        // Profile::toArray()'s "omit if null OR empty" rule — a plain null
        // check alone wouldn't catch a regression here.
        $store = new ProfileStore();
        $store->add(new Profile('t', 'h', 22, 'u', AuthMethod::Key, remotePath: '', localStartPath: ''));
        $store->save();

        $rawToml = file_get_contents(ProfileStore::configPath());
        self::assertStringNotContainsString('remote_path', $rawToml);
        self::assertStringNotContainsString('local_start_path', $rawToml);

        $reloaded = ProfileStore::load();
        self::assertNull($reloaded->profiles[0]->remotePath);
        self::assertNull($reloaded->profiles[0]->localStartPath);
    }

    #[Test]
    public function addRemoveUpdateMutateTheProfileList(): void
    {
        $store = new ProfileStore();
        $store->add(new Profile('a', 'h', 22, 'u', AuthMethod::Key));
        $store->add(new Profile('b', 'h', 22, 'u', AuthMethod::Key));

        $store->update(1, new Profile('b-renamed', 'h', 22, 'u', AuthMethod::Key));
        self::assertSame('b-renamed', $store->profiles[1]->name);

        $store->remove(0);
        self::assertCount(1, $store->profiles);
        self::assertSame('b-renamed', $store->profiles[0]->name);
    }

    #[Test]
    public function removeAtAnOutOfRangeIndexIsANoOp(): void
    {
        $store = new ProfileStore();
        $store->add(new Profile('a', 'h', 22, 'u', AuthMethod::Key));

        $store->remove(5);

        self::assertCount(1, $store->profiles);
    }

    #[Test]
    public function configPathThrowsWhenHomeIsNotSet(): void
    {
        unset($_SERVER['HOME']);
        // ProfileStore::configPath() falls back to getenv('HOME') — clear
        // that too, otherwise the shell's real HOME env var masks the case
        // we're actually testing.
        putenv('HOME');

        $this->expectException(HomeDirNotFoundException::class);

        ProfileStore::configPath();
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
