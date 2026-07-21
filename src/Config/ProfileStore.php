<?php

declare(strict_types=1);

namespace Vela\Config;

use Internal\Toml\Toml;

/** Mirrors vela's src/config/profiles.rs ProfileStore. */
final class ProfileStore
{
    /** @var Profile[] */
    public array $profiles = [];

    public static function load(): self
    {
        $path = self::configPath();
        if (!is_file($path)) {
            return new self();
        }

        $mode = fileperms($path) & 0o777;
        if ($mode !== 0o600) {
            throw new UnsafePermissionsException($path, $mode);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ConfigException("Cannot read {$path}");
        }

        $data = Toml::parseToArray($content);

        $store = new self();
        foreach ($data['profile'] ?? [] as $entry) {
            $store->profiles[] = Profile::fromArray($entry);
        }

        return $store;
    }

    public function save(): void
    {
        $path = self::configPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ConfigException("Cannot create directory {$dir}");
        }

        $data = ['profile' => array_map(
            static fn (Profile $p): array => $p->toArray(),
            $this->profiles,
        )];
        $content = (string) Toml::encode($data);

        if (file_put_contents($path, $content) === false) {
            throw new ConfigException("Cannot write {$path}");
        }

        // Enforce 0600 — only owner can read/write
        chmod($path, 0600);
    }

    public function add(Profile $profile): void
    {
        $this->profiles[] = $profile;
    }

    public function remove(int $index): void
    {
        if (isset($this->profiles[$index])) {
            array_splice($this->profiles, $index, 1);
        }
    }

    public function update(int $index, Profile $profile): void
    {
        if (isset($this->profiles[$index])) {
            $this->profiles[$index] = $profile;
        }
    }

    public static function configPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (!$home) {
            throw new HomeDirNotFoundException();
        }

        return rtrim($home, '/') . '/.config/vela/profiles.toml';
    }
}
