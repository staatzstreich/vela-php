# Patches

Applied automatically by [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches)
on `composer install`/`update` (see `extra.patches` in `composer.json`).

## `php-tui-term-event-parser-more-flag.patch`

Fixes a bug in `php-tui/term` 0.3.4's `EventParser::advance()`
(`src/EventParser.php`) that can make a standalone `Esc` keypress get lost.

**Root cause**: `advance()` processes one read chunk byte by byte, tracking
whether more bytes might still be coming (`$more`) to decide whether a lone
ESC byte could be the start of a longer escape sequence or should resolve
immediately as a standalone `Esc` key. The buggy line:

```php
$more = $index + 1 < strlen($line) || $more;
```

reassigns `$more` itself instead of computing a fresh per-byte value. Once
any earlier byte in the chunk had more bytes after it, `$more` becomes
`true` and stays `true` for every remaining byte in that call — including a
byte that is genuinely the last one in the chunk. A trailing lone ESC then
gets treated as "might still be a sequence" and is left in the internal
buffer. Since `advance()` only runs again when new input arrives, that ESC
just sits there until something else shows up — in practice, a *second*
Escape keypress combines with the stranded first one into a recognized
"ESC-ESC" pattern, which is why pressing Escape twice always "unstuck" it.

**Reproduction**: two or more arrow-key presses (each an `ESC [ A/B/C/D`
sequence) followed by a character key, all read in one chunk, then a single
Escape — reliably lost. Verified at the time with a scripted pty session
(`expect`) sending exactly that sequence to `bin/textinput-demo.php`, a
standalone TextInput demo script since removed (superseded by real dialogs
in milestone 6 and `tests/Ui/TextInputTest.php`).

**Fix**: compute a new local variable (`$inputAvailable`) fresh from the
*original*, unmodified `$more` parameter on each iteration, instead of
overwriting `$more`. `EventParser` and `SyncTtyEventProvider` are both
`final`, so this couldn't be fixed by extending/wrapping either class from
our own code — hence the patch.

Not yet reported upstream (php-tui/term).

## `php-tui-term-ansipainter-buffered-write.patch`

Fixes a real (if unremarkable-looking) performance bug in `AnsiPainter::paint()`
(`src/Painter/AnsiPainter.php`): it called `$this->writer->write(...)` once per
queued `Action` — one unbuffered `fwrite()` syscall per cursor move, per color
change, per printed string fragment. A single full-screen repaint can queue
many thousands of actions, so this was many thousands of tiny syscalls per
frame instead of one.

**Symptom**: noticeably laggy typing in `Vela\Ui\TextInput` fields, worse with
fast typing or pasting — reported after live testing milestone 6's dialogs.
Measured in isolation: a single `display->draw()` call taking anywhere from
~250ms to 3+ seconds under a `expect`-scripted pty (see the milestone 6 writeup
in the main README for how that investigation also uncovered — and ruled out —
a red herring in `expect`'s own pty-draining behavior; this fix is the one
that actually addresses real-terminal typing lag).

**Fix**: `drawCommand()` now returns the ANSI string fragment for each action
instead of writing it directly; `paint()` concatenates all of them into one
buffer and issues a single `write()` call at the end of the frame. Behavior
is unchanged (same bytes, same order), just batched into one syscall instead
of one per action. `AnsiPainter` is `final`, so — same as the other patch —
this couldn't be done by extending/wrapping it from our own code.

Also worth knowing about (not part of this patch): `bin/vela.php`'s main loop
was changed to drain *all* currently-buffered input events before triggering
a redraw, rather than redrawing after every single event. Without that, even
with this patch, a paste would still visibly "trickle in" one character at a
time — each redraw is cheap, but a paste of N characters used to mean N
separate full redraws in a row.

Not yet reported upstream (php-tui/term).

## `php-tui-term-windows-raw-mode.patch`

Adds a new file, `src/RawMode/WindowsRawMode.php` — `php-tui/term` had no
Windows support at all (its own README: "shouldn't be hard to implement, but
I don't have windows so..."). `RawMode`'s two other implementations
(`SttyRawMode`, shell out to `stty`; `TestRawMode`, a fake for tests) have no
Windows equivalent, so `Terminal::new()`'s default `SttyRawMode::new()` simply
doesn't work there.

`WindowsRawMode` uses PHP's FFI extension to call `kernel32.dll`'s
`GetConsoleMode`/`SetConsoleMode` directly, toggling
`ENABLE_VIRTUAL_TERMINAL_INPUT`/`ENABLE_VIRTUAL_TERMINAL_PROCESSING` so arrow
keys, function keys etc. arrive as the same ANSI escape sequences
`EventParser` already parses on POSIX — confirmed by live testing on a real
Windows 11 VM (PHP 8.5.8 NTS, FFI enabled): every key vela-php uses (F1–F10,
F12, arrows, Ctrl+C) arrived exactly as expected, and `enable()`/`disable()`/
`isEnabled()` all behaved correctly.

Not wired up automatically in this project yet — `Terminal::new()` already
supports constructor injection (`Terminal::new(rawMode: WindowsRawMode::new())`),
which is how the upstream PR uses it too.

Submitted upstream as [php-tui/term#19](https://github.com/php-tui/term/pull/19),
not yet merged. This patch exists so vela-php doesn't have to wait for that —
same `WindowsRawMode.php` content either way, so once the PR lands and a new
`php-tui/term` release includes it, this patch (and this whole section) can
just be deleted.
