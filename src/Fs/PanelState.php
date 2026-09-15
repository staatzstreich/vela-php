<?php

declare(strict_types=1);

namespace Vela\Fs;

use RuntimeException;

/**
 * State of a single file panel. Mirrors vela's src/app.rs PanelState,
 * currently local-filesystem only — load_remote()/refresh_remote() will be
 * added once SFTP support lands.
 */
final class PanelState
{
    /** @var list<FileEntry> */
    public array $entries = [];

    public int $selected = 0;

    /** @var array<int,true> set of marked entry indices */
    public array $marked = [];

    public function __construct(public string $path)
    {
    }

    public function toggleMark(): void
    {
        $entry = $this->entries[$this->selected] ?? null;
        if ($entry === null || $entry->name === '..') {
            return;
        }
        if (isset($this->marked[$this->selected])) {
            unset($this->marked[$this->selected]);
        } else {
            $this->marked[$this->selected] = true;
        }
    }

    public function markAll(): void
    {
        $eligible = [];
        foreach ($this->entries as $i => $entry) {
            if ($entry->name !== '..') {
                $eligible[] = $i;
            }
        }

        $allMarked = $eligible !== [] && array_reduce(
            $eligible,
            fn (bool $carry, int $i): bool => $carry && isset($this->marked[$i]),
            true
        );

        if ($allMarked) {
            $this->marked = [];

            return;
        }

        $marked = [];
        foreach ($eligible as $i) {
            $marked[$i] = true;
        }
        $this->marked = $marked;
    }

    public function clearMarks(): void
    {
        $this->marked = [];
    }

    public function moveUp(): void
    {
        if ($this->selected > 0) {
            $this->selected--;
        }
    }

    public function moveDown(): void
    {
        if ($this->selected + 1 < count($this->entries)) {
            $this->selected++;
        }
    }

    public function loadLocal(): void
    {
        $this->entries = [];
        $this->marked = [];

        if ($this->hasParent()) {
            $this->entries[] = new FileEntry('..', null, null, true);
        }

        $names = @scandir($this->path);
        if ($names === false) {
            throw new RuntimeException("Cannot read directory: {$this->path}");
        }

        $loaded = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = self::joinPath($this->path, $name);
            $isDir = is_dir($full);
            $mtime = @filemtime($full);
            $loaded[] = new FileEntry(
                name: $name,
                size: $isDir ? null : (@filesize($full) ?: 0),
                modifiedAt: $mtime !== false ? $mtime : null,
                isDir: $isDir,
            );
        }

        usort(
            $loaded,
            fn (FileEntry $a, FileEntry $b): int => ($b->isDir <=> $a->isDir) ?: strcmp($a->name, $b->name)
        );

        array_push($this->entries, ...$loaded);
        $this->selected = min($this->selected, max(count($this->entries) - 1, 0));
    }

    /**
     * Find the nearest entry whose name contains $query (case-insensitive),
     * starting at $from (inclusive) and wrapping around the list. $forward
     * controls search direction, mirroring vim's `/` and `?`.
     */
    public function findMatch(string $query, int $from, bool $forward): ?int
    {
        if ($query === '' || $this->entries === []) {
            return null;
        }
        $needle = mb_strtolower($query);
        $len = count($this->entries);
        for ($offset = 0; $offset < $len; $offset++) {
            $idx = $forward
                ? ($from + $offset) % $len
                : ($from + $len - $offset) % $len;
            if (str_contains(mb_strtolower($this->entries[$idx]->name), $needle)) {
                return $idx;
            }
        }

        return null;
    }

    /** Used for local panel navigation only. */
    public function enterSelected(): void
    {
        $entry = $this->entries[$this->selected] ?? null;
        if ($entry === null || !$entry->isDir) {
            return;
        }

        $this->path = $entry->name === '..'
            ? self::parentOf($this->path)
            : self::joinPath($this->path, $entry->name);
        $this->selected = 0;
        $this->loadLocal();
    }

    /** Navigate to the parent directory (Backspace key) — local only. */
    public function goUp(): void
    {
        if (!$this->hasParent()) {
            return;
        }
        $this->path = self::parentOf($this->path);
        $this->selected = 0;
        $this->loadLocal();
    }

    private function hasParent(): bool
    {
        return self::parentOf($this->path) !== $this->path;
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
}
