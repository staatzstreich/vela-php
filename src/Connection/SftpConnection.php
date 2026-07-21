<?php

declare(strict_types=1);

namespace Vela\Connection;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Vela\Config\AuthMethod;
use Vela\Config\Profile;
use Vela\Fs\FileEntry;

/**
 * An active SFTP session. Mirrors vela's src/connection/sftp.rs
 * SftpConnection, scoped to connect + browse for this milestone —
 * rename/mkdir/delete/upload/download/tail follow in later milestones.
 */
final class SftpConnection
{
    private const MATCH = 'match';
    private const NOT_FOUND = 'not_found';
    private const MISMATCH = 'mismatch';

    /**
     * phpseclib only defines its NET_SFTP_TYPE_* globals inside the SFTP
     * constructor (via define_array()), so referencing the global constant
     * here would be a timing trap. Value taken from its own $file_types map.
     */
    private const TYPE_DIRECTORY = 2;

    private function __construct(
        private readonly SFTP $sftp,
        public string $remotePath,
        /** The login home directory — never changes after connect. */
        private readonly string $home,
        public readonly string $host,
        public readonly string $user,
        public readonly Profile $profile,
    ) {
    }

    public static function connect(Profile $profile, ?string $password = null): self
    {
        $sftp = new SFTP($profile->host, $profile->port, 10);

        self::verifyHostKey($sftp, $profile->host, $profile->port);
        self::authenticate($sftp, $profile, $password);

        $home = $sftp->realpath('.');
        if ($home === false) {
            throw new SftpException('Could not resolve remote home directory');
        }

        return new self($sftp, $home, $home, $profile->host, $profile->user, $profile);
    }

    /** List the current remote directory. Dirs first, then files, alphabetically. */
    public function listDir(): array
    {
        $entries = [];
        if ($this->remotePath !== '/') {
            $entries[] = new FileEntry('..', null, null, true);
        }

        $raw = $this->sftp->rawlist($this->remotePath);
        if ($raw === false) {
            throw new SftpException("Cannot list directory: {$this->remotePath}");
        }

        $loaded = [];
        foreach ($raw as $name => $stat) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $loaded[] = self::fileEntryFromStat($name, $stat);
        }

        usort(
            $loaded,
            fn (FileEntry $a, FileEntry $b): int => ($b->isDir <=> $a->isDir) ?: strcmp($a->name, $b->name)
        );

        array_push($entries, ...$loaded);

        return $entries;
    }

    /**
     * Change into a subdirectory and return the new listing.
     *
     * @throws SftpException if $name contains '/' (path-traversal guard
     *   against a crafted server response, mirrored from the Rust version)
     */
    public function enterDir(string $name): array
    {
        if ($name !== '..' && str_contains($name, '/')) {
            throw new SftpException("Invalid entry name: '{$name}'");
        }

        $this->remotePath = $name === '..' ? self::parentOf($this->remotePath) : self::joinPath($this->remotePath, $name);

        return $this->listDir();
    }

    public function goUp(): array
    {
        $this->remotePath = self::parentOf($this->remotePath);

        return $this->listDir();
    }

    private static function fileEntryFromStat(string $name, array $stat): FileEntry
    {
        $isDir = ($stat['type'] ?? null) === self::TYPE_DIRECTORY;

        return new FileEntry(
            name: $name,
            size: $isDir ? null : (int) ($stat['size'] ?? 0),
            modifiedAt: isset($stat['mtime']) ? (int) $stat['mtime'] : null,
            isDir: $isDir,
            permissions: isset($stat['mode']) ? self::formatPermissions((int) $stat['mode']) : null,
        );
    }

    /** Convert a Unix mode bitmask into a "rwxr-xr-x" style string. */
    private static function formatPermissions(int $mode): string
    {
        $flags = [0o400, 0o200, 0o100, 0o040, 0o020, 0o010, 0o004, 0o002, 0o001];
        $chars = ['r', 'w', 'x', 'r', 'w', 'x', 'r', 'w', 'x'];

        $out = '';
        foreach ($flags as $i => $bit) {
            $out .= ($mode & $bit) !== 0 ? $chars[$i] : '-';
        }

        return $out;
    }

    private static function authenticate(SFTP $sftp, Profile $profile, ?string $password): void
    {
        if ($profile->auth === AuthMethod::Key) {
            $keyPath = self::expandTilde($profile->keyPath ?? '~/.ssh/id_rsa');
            if (!is_file($keyPath)) {
                throw new KeyNotFoundException($keyPath);
            }

            $mode = fileperms($keyPath) & 0o777;
            if (($mode & 0o077) !== 0) {
                throw new InsecureKeyPermissionsException($keyPath, $mode);
            }

            $key = PublicKeyLoader::load(file_get_contents($keyPath));
            $ok = $sftp->login($profile->user, $key);
        } else {
            $ok = $sftp->login($profile->user, $password ?? '');
        }

        if (!$ok) {
            throw new AuthFailedException();
        }
    }

    /** Check the server's host key against ~/.ssh/known_hosts. */
    private static function verifyHostKey(SFTP $sftp, string $host, int $port): void
    {
        $serverKey = $sftp->getServerPublicHostKey();
        if ($serverKey === false || !str_contains($serverKey, ' ')) {
            throw new HostKeyMismatchException($host);
        }

        [$keyType, $keyB64] = explode(' ', $serverKey, 2);
        $keyBytes = base64_decode($keyB64, true);
        if ($keyBytes === false) {
            throw new HostKeyMismatchException($host);
        }

        $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', $keyBytes, true)), '=');
        $checkHost = $port === 22 ? $host : "[{$host}]:{$port}";

        $result = self::checkKnownHosts(self::expandTilde('~/.ssh/known_hosts'), $checkHost, $keyType, $keyB64);

        match ($result) {
            self::MATCH => null,
            self::NOT_FOUND => throw new UnknownHostKeyException($host, $port, $fingerprint, $keyType, $keyBytes),
            default => throw new HostKeyMismatchException($host),
        };
    }

    /**
     * Only matches plain (non-hashed) known_hosts entries — hashed
     * hostnames (`|1|salt|hash`, OpenSSH's HashKnownHosts) aren't parsed.
     * Good enough for entries vela itself writes; a real-world
     * known_hosts with hashing enabled would fall through to NOT_FOUND.
     */
    private static function checkKnownHosts(string $path, string $checkHost, string $keyType, string $keyB64): string
    {
        if (!is_file($path)) {
            return self::NOT_FOUND;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $sawHost = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 4);
            if ($parts === false || count($parts) < 3) {
                continue;
            }
            [$hostsField, $lineKeyType, $lineKeyB64] = $parts;
            if (!in_array($checkHost, explode(',', $hostsField), true)) {
                continue;
            }
            $sawHost = true;
            if ($lineKeyType === $keyType && $lineKeyB64 === $keyB64) {
                return self::MATCH;
            }
        }

        return $sawHost ? self::MISMATCH : self::NOT_FOUND;
    }

    /** Append a trusted host key to ~/.ssh/known_hosts. */
    public static function addToKnownHosts(string $host, int $port, string $keyType, string $keyBytes): void
    {
        $path = self::expandTilde('~/.ssh/known_hosts');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $hostname = $port === 22 ? $host : "[{$host}]:{$port}";
        $entry = "{$hostname} {$keyType} " . base64_encode($keyBytes) . "\n";

        $fh = fopen($path, 'a');
        if ($fh === false) {
            throw new SftpException("Cannot write {$path}");
        }
        fwrite($fh, $entry);
        fclose($fh);
    }

    private static function parentOf(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' ? '/' : $parent;
    }

    private static function joinPath(string $base, string $name): string
    {
        return $base === '/' ? '/' . $name : rtrim($base, '/') . '/' . $name;
    }

    private static function expandTilde(string $path): string
    {
        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: '.');
        if ($path === '~') {
            return $home;
        }
        if (str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }

        return $path;
    }
}
