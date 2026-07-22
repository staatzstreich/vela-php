<?php

declare(strict_types=1);

namespace Vela\Config;

/** Mirrors vela's src/config/profiles.rs Profile. */
final class Profile
{
    public function __construct(
        public string $name,
        public string $host,
        public int $port,
        public string $user,
        public AuthMethod $auth,
        public ?string $keyPath = null,
        /** Remote directory to switch into right after connecting. Empty/null means server default. */
        public ?string $remotePath = null,
        /** Local directory for the left panel right after connecting. Empty/null means keep current. */
        public ?string $localStartPath = null,
        public bool $hasSavedPassword = false,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: self::stringOr($data['name'] ?? null, ''),
            host: self::stringOr($data['host'] ?? null, ''),
            port: self::intOr($data['port'] ?? null, 22),
            user: self::stringOr($data['user'] ?? null, ''),
            auth: AuthMethod::from(self::stringOr($data['auth'] ?? null, 'key')),
            keyPath: self::nullableString($data['key_path'] ?? null),
            remotePath: self::nullableString($data['remote_path'] ?? null),
            localStartPath: self::nullableString($data['local_start_path'] ?? null),
            hasSavedPassword: is_bool($data['has_saved_password'] ?? null) ? $data['has_saved_password'] : false,
        );
    }

    /**
     * Scalar coercion for values coming out of a hand-editable TOML file —
     * a corrupted/malformed field (e.g. a stray `[[sub_table]]` where a
     * plain value was expected) falls back to $default instead of a blind
     * cast silently turning an array into "Array" or 1.
     */
    private static function stringOr(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Field order and omit-if-empty rules mirror the Rust struct's serde
     * attributes, so files written by either side stay diff-friendly.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'auth' => $this->auth->value,
        ];

        if ($this->keyPath !== null) {
            $out['key_path'] = $this->keyPath;
        }
        if ($this->remotePath !== null && $this->remotePath !== '') {
            $out['remote_path'] = $this->remotePath;
        }
        if ($this->localStartPath !== null && $this->localStartPath !== '') {
            $out['local_start_path'] = $this->localStartPath;
        }
        if ($this->hasSavedPassword) {
            $out['has_saved_password'] = true;
        }

        return $out;
    }
}
