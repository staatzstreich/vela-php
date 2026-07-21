<?php

declare(strict_types=1);

namespace Vela\Config;

/**
 * OS credential store, mirroring vela's src/config/profiles.rs keyring
 * helpers (service name "vela", account = profile name). Rust uses the
 * `keyring` crate (native Security-framework / D-Bus bindings); PHP has no
 * established equivalent, so this shells out to the platform CLI instead:
 * macOS `security`, Linux `secret-tool` (libsecret).
 *
 * Caveat vs. the native binding: `security add-generic-password` takes the
 * password as a process argument, so it is briefly visible in `ps` output
 * while the command runs. Acceptable for this prototype; a Security.framework
 * FFI binding would be the way out if that ever matters.
 */
final class Keychain
{
    private const SERVICE = 'vela';

    public static function isSupported(): bool
    {
        return self::binary() !== null;
    }

    public static function savePassword(string $profileName, string $password): void
    {
        $bin = self::binary();
        if ($bin === null) {
            throw new ConfigException('Kein Keychain-Backend verfügbar (security/secret-tool nicht gefunden)');
        }

        if ($bin === 'security') {
            // -U = update in place if the entry already exists
            $result = self::run([
                'security', 'add-generic-password',
                '-a', $profileName, '-s', self::SERVICE, '-w', $password, '-U',
            ]);
        } else {
            $result = self::run(
                ['secret-tool', 'store', '--label', 'vela', 'service', self::SERVICE, 'account', $profileName],
                stdin: $password,
            );
        }

        if ($result['exit'] !== 0) {
            throw new ConfigException('Keychain-Fehler: ' . trim($result['stderr']));
        }
    }

    /** Null when no entry exists for this profile. */
    public static function loadPassword(string $profileName): ?string
    {
        $bin = self::binary();
        if ($bin === null) {
            return null;
        }

        $result = $bin === 'security'
            ? self::run(['security', 'find-generic-password', '-a', $profileName, '-s', self::SERVICE, '-w'])
            : self::run(['secret-tool', 'lookup', 'service', self::SERVICE, 'account', $profileName]);

        if ($result['exit'] !== 0) {
            return null;
        }

        return rtrim($result['stdout'], "\n");
    }

    /** Best-effort — a missing entry is not an error, mirroring the Rust side. */
    public static function deletePassword(string $profileName): void
    {
        $bin = self::binary();
        if ($bin === null) {
            return;
        }

        if ($bin === 'security') {
            self::run(['security', 'delete-generic-password', '-a', $profileName, '-s', self::SERVICE]);
        } else {
            self::run(['secret-tool', 'clear', 'service', self::SERVICE, 'account', $profileName]);
        }
    }

    private static function binary(): ?string
    {
        foreach (['security', 'secret-tool'] as $candidate) {
            $out = shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
            if (is_string($out) && trim($out) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param string[] $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function run(array $argv, ?string $stdin = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
