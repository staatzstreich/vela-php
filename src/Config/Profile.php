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
            name: (string) ($data['name'] ?? ''),
            host: (string) ($data['host'] ?? ''),
            port: (int) ($data['port'] ?? 22),
            user: (string) ($data['user'] ?? ''),
            auth: AuthMethod::from((string) ($data['auth'] ?? 'key')),
            keyPath: isset($data['key_path']) ? (string) $data['key_path'] : null,
            remotePath: isset($data['remote_path']) ? (string) $data['remote_path'] : null,
            localStartPath: isset($data['local_start_path']) ? (string) $data['local_start_path'] : null,
            hasSavedPassword: (bool) ($data['has_saved_password'] ?? false),
        );
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
