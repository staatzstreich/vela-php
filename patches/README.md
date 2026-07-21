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
Escape — reliably lost. Verified with a scripted pty session
(`expect`) sending exactly that sequence to `bin/textinput-demo.php`.

**Fix**: compute a new local variable (`$inputAvailable`) fresh from the
*original*, unmodified `$more` parameter on each iteration, instead of
overwriting `$more`. `EventParser` and `SyncTtyEventProvider` are both
`final`, so this couldn't be fixed by extending/wrapping either class from
our own code — hence the patch.

Not yet reported upstream (php-tui/term).
