<?php

declare(strict_types=1);

namespace Vela\Dialog;

use Vela\Config\AuthMethod;
use Vela\Config\Profile;

/**
 * Mirrors vela's src/app.rs NewProfileForm, minus the save_password/password
 * fields — those need OS-keychain access, which is milestone 8, not this
 * one. Saved profiles from this form always have hasSavedPassword=false;
 * password-auth profiles fall back to the password dialog every connect
 * until keychain integration lands.
 */
final class NewProfileForm
{
    public const NAME = 0;
    public const HOST = 1;
    public const PORT = 2;
    public const USER = 3;
    public const AUTH = 4;
    public const KEY_PATH = 5;
    public const REMOTE_PATH = 6;
    public const LOCAL_PATH = 7;
    public const FIELD_COUNT = 8;

    public string $name = '';

    public string $host = '';

    public string $port = '22';

    public string $user = '';

    public AuthMethod $auth = AuthMethod::Key;

    public string $keyPath = '~/.ssh/id_rsa';

    public string $remotePath = '';

    public string $localStartPath = '';

    public static function fromProfile(Profile $p): self
    {
        $form = new self();
        $form->name = $p->name;
        $form->host = $p->host;
        $form->port = (string) $p->port;
        $form->user = $p->user;
        $form->auth = $p->auth;
        $form->keyPath = $p->keyPath ?? '~/.ssh/id_rsa';
        $form->remotePath = $p->remotePath ?? '';
        $form->localStartPath = $p->localStartPath ?? '';

        return $form;
    }

    public function fieldValue(int $field): ?string
    {
        return match ($field) {
            self::NAME => $this->name,
            self::HOST => $this->host,
            self::PORT => $this->port,
            self::USER => $this->user,
            self::KEY_PATH => $this->keyPath,
            self::REMOTE_PATH => $this->remotePath,
            self::LOCAL_PATH => $this->localStartPath,
            default => null,
        };
    }

    public function setFieldValue(int $field, string $value): void
    {
        match ($field) {
            self::NAME => $this->name = $value,
            self::HOST => $this->host = $value,
            self::PORT => $this->port = $value,
            self::USER => $this->user = $value,
            self::KEY_PATH => $this->keyPath = $value,
            self::REMOTE_PATH => $this->remotePath = $value,
            self::LOCAL_PATH => $this->localStartPath = $value,
            default => null,
        };
    }

    public function isFieldVisible(int $field): bool
    {
        return match ($field) {
            self::KEY_PATH => $this->auth === AuthMethod::Key,
            default => true,
        };
    }

    public function nextField(int $current): int
    {
        $f = $current;
        for ($i = 0; $i < self::FIELD_COUNT; $i++) {
            $f = ($f + 1) % self::FIELD_COUNT;
            if ($this->isFieldVisible($f)) {
                return $f;
            }
        }

        return $current;
    }

    public function prevField(int $current): int
    {
        $f = $current;
        for ($i = 0; $i < self::FIELD_COUNT; $i++) {
            $f = ($f - 1 + self::FIELD_COUNT) % self::FIELD_COUNT;
            if ($this->isFieldVisible($f)) {
                return $f;
            }
        }

        return $current;
    }

    public function toProfile(): ?Profile
    {
        $port = filter_var(trim($this->port), FILTER_VALIDATE_INT);
        if ($port === false || $port < 0 || $port > 65535) {
            return null;
        }

        $name = trim($this->name);
        $host = trim($this->host);
        $user = trim($this->user);
        if ($name === '' || $host === '' || $user === '') {
            return null;
        }

        $keyPath = trim($this->keyPath);
        $remotePath = trim($this->remotePath);
        $localStartPath = trim($this->localStartPath);

        return new Profile(
            name: $name,
            host: $host,
            port: $port,
            user: $user,
            auth: $this->auth,
            keyPath: $keyPath !== '' ? $keyPath : null,
            remotePath: $remotePath !== '' ? $remotePath : null,
            localStartPath: $localStartPath !== '' ? $localStartPath : null,
            hasSavedPassword: false,
        );
    }
}
