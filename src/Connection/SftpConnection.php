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
 * SftpConnection: connect + browse, transfer primitives, and the
 * rename/mkdir/delete/change_to_absolute operations the dialog system
 * needs. tail_remote_file is still a later (polish) milestone.
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
        $sftp = new SFTP(self::resolveIPv4Preferred($profile->host), $profile->port, 10);

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

    /**
     * Switch to an absolute remote path and return the new listing.
     * Expands a leading `~` to the login home directory resolved at
     * connect time. Uses realpath to canonicalise (resolves symlinks,
     * "..", etc.) and simultaneously verify the path exists.
     */
    public function changeToAbsolute(string $raw): array
    {
        $expanded = match (true) {
            $raw === '~' => $this->home,
            str_starts_with($raw, '~/') => $this->home . substr($raw, 1),
            default => $raw,
        };

        $canonical = $this->sftp->realpath($expanded);
        if ($canonical === false) {
            throw new SftpException("Pfad nicht gefunden '{$expanded}'");
        }

        if ($this->isRemoteDir($canonical) !== true) {
            throw new SftpException("'{$canonical}' ist kein Verzeichnis");
        }

        $this->remotePath = $canonical;

        return $this->listDir();
    }

    /** Rename (or move) an entry in the current remote directory. */
    public function renameEntry(string $oldName, string $newName): void
    {
        $old = self::joinPath($this->remotePath, $oldName);
        $new = self::joinPath($this->remotePath, $newName);
        if (!$this->sftp->rename($old, $new)) {
            throw new SftpException("Rename failed: {$oldName} -> {$newName}");
        }
    }

    /**
     * Create a directory in the current remote directory. Unlike
     * mkdirRemote() (used by the transfer engine, which tolerates an
     * already-existing target directory), this raises on any failure —
     * the mkdir dialog should surface "already exists" as an error.
     */
    public function createDirectory(string $name): void
    {
        $path = self::joinPath($this->remotePath, $name);
        if (!$this->sftp->mkdir($path, 0o755)) {
            throw new SftpException("mkdir failed: {$path}");
        }
    }

    /** Delete a file in the current remote directory. */
    public function deleteFile(string $name): void
    {
        $path = self::joinPath($this->remotePath, $name);
        if (!$this->sftp->delete($path, false)) {
            throw new SftpException("Delete failed: {$path}");
        }
    }

    /** Recursively delete a directory and all its contents. */
    public function deleteDirectory(string $name): void
    {
        $path = self::joinPath($this->remotePath, $name);
        if (!$this->sftp->delete($path, true)) {
            throw new SftpException("Delete failed: {$path}");
        }
    }

    /** Read a remote text file and return its last $maxLines lines. */
    public function tailRemoteFile(string $path, int $maxLines): array
    {
        $content = $this->sftp->get($path);
        if ($content === false) {
            throw new SftpException("Cannot read {$path}");
        }

        $lines = explode("\n", $content);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -$maxLines);
    }

    /** Null means stat failed (path doesn't exist / no permission). */
    public function isRemoteDir(string $path): ?bool
    {
        $stat = $this->sftp->stat($path);
        if ($stat === false) {
            return null;
        }

        return ($stat['type'] ?? null) === self::TYPE_DIRECTORY;
    }

    /** Null means stat failed, or the server didn't report a size. */
    public function remoteFileSize(string $path): ?int
    {
        $stat = $this->sftp->stat($path);
        if ($stat === false || !isset($stat['size'])) {
            return null;
        }

        return (int) $stat['size'];
    }

    /** @return array<string,bool> child name => isDir, excluding "." and ".." */
    public function childNames(string $path): array
    {
        $raw = $this->sftp->rawlist($path);
        if ($raw === false) {
            return [];
        }

        $out = [];
        foreach ($raw as $name => $stat) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $out[$name] = ($stat['type'] ?? null) === self::TYPE_DIRECTORY;
        }

        return $out;
    }

    /** No-op if the directory already exists (mirrors the Rust side's tolerant mkdir). */
    public function mkdirRemote(string $path): void
    {
        if ($this->isRemoteDir($path) === true) {
            return;
        }
        $this->sftp->mkdir($path, 0o755);
    }

    /** @param callable(int):void $progressCallback receives cumulative bytes sent so far */
    public function putFile(string $localPath, string $remotePath, callable $progressCallback): void
    {
        $ok = $this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progressCallback);
        if ($ok === false) {
            throw new SftpException("Upload failed: {$remotePath}");
        }
    }

    /** @param callable(int):void $progressCallback receives cumulative bytes received so far */
    public function getFile(string $remotePath, string $localPath, callable $progressCallback): void
    {
        $ok = $this->sftp->get($remotePath, $localPath, 0, -1, $progressCallback);
        if ($ok === false) {
            throw new SftpException("Download failed: {$remotePath}");
        }
    }

    public function joinRemotePath(string $base, string $name): string
    {
        return self::joinPath($base, $name);
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

    /**
     * PHP's fsockopen() (which phpseclib uses internally) resolves a
     * hostname once and doesn't fall back if that address is unreachable.
     * Dual-stack DNS records commonly resolve IPv6 first (RFC 6724), so a
     * host with a broken/unrouted AAAA record hangs until timeout even
     * though IPv4 works fine — Rust's TcpStream::connect doesn't have this
     * problem because it tries every resolved address in turn. We can't
     * get that automatic fallback in PHP, so resolve to IPv4 explicitly
     * (gethostbyname() is an A-record-only lookup) and connect to that.
     */
    private static function resolveIPv4Preferred(string $host): string
    {
        // Returns the original string unchanged if resolution fails (e.g.
        // $host was already an IP literal, or has no A record) — safe to
        // call unconditionally.
        return gethostbyname($host);
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
