# vela-php

Feasibility spike: exploring whether [vela](https://github.com/) (a Rust/ratatui dual-panel
SFTP TUI client) can be rebuilt in PHP using [php-tui/php-tui](https://github.com/php-tui/php-tui).

Status: spike passed — php-tui's terminal backend works reliably on macOS. (`bin/spike.php`,
the minimal script that proved this, was removed once `bin/vela.php` existed and covered the
same ground far more thoroughly.)

Milestone 1 done: local dual-panel file browser, no SFTP yet. Run with:

```
composer install
php bin/vela.php
```

Keys: `Tab` switch panel, `↑`/`↓` move, `Enter` open directory, `Backspace` go up,
`Space` mark, `*` mark all, `q` quit.

Milestone 2 done: `Vela\Config\ProfileStore` reads/writes `~/.config/vela/profiles.toml`
in the same TOML shape as the Rust version (0600-enforced), so both can share saved
connections.

Milestone 3 done: `Vela\Connection\SftpConnection` (via phpseclib) connects, verifies the
server's host key against `~/.ssh/known_hosts`, and browses a remote directory — the right
panel switches from local to remote listing once connected. There's no connect dialog yet
(that's milestone 6), so it's wired up through a CLI flag in the meantime:

```
php bin/vela.php --profile="Lokal"
```

Looks up the named profile from `~/.config/vela/profiles.toml`, prompts for a password on
the console first if the profile uses password auth, then connects before entering the TUI.
Key-file auth, mkdir/rename/delete, and actual transfers are later milestones.

Note: on a dual-stack host (has both an A and AAAA DNS record) whose IPv6 route doesn't
actually work, connecting can time out even though the Rust version connects fine — Rust's
`TcpStream::connect` tries every resolved address in turn, PHP's underlying `fsockopen()`
doesn't. Worked around by resolving to IPv4 explicitly before connecting
(`SftpConnection::resolveIPv4Preferred()`).

Milestone 4 done: `Vela\Ui\TextInput` — php-tui has no built-in text input widget
([php-tui/php-tui#234](https://github.com/php-tui/php-tui/issues/234)), so this is one
shared insert/backspace/delete/move-cursor component (character-indexed, unicode-safe via
`mb_*`) for every dialog field that needs one, replacing what vela's Rust side reimplements
per-dialog. `Vela\Ui\TextInputRenderer` draws it as text with an inverted-style block
cursor, with an optional masked mode for password fields. Verified at the time via a
standalone demo script (`bin/textinput-demo.php` — since removed: milestone 6 built real
dialogs to host it, and `tests/Ui/TextInputTest.php` now covers the cursor logic, including
emoji/umlaut mid-string edits, more thoroughly than eyeballing a demo ever did) and a real
pty session for typing/arrows/backspace/delete.

**Fixed** the php-tui/term quirk noted above (a bare Escape after 2+ arrow-key presses
could get silently dropped) via a Composer patch — `EventParser::advance()` had a "sticky"
`$more` flag that, once true, stayed true for the rest of that read chunk, stranding a
trailing lone ESC in its internal buffer indefinitely (nothing flushes it once no more
input arrives). See `patches/README.md` for the full root-cause writeup and
`patches/php-tui-term-event-parser-more-flag.patch` for the fix; applied automatically via
`cweagans/composer-patches` on `composer install`. Verified against the exact sequence that
used to reproduce it, plus a regression check that milestone 1's nav/quit still works.

Milestone 5 done: `Vela\Transfer\TransferEngine` ports `upload_batch()`/`download_batch()`
from `connection/sftp.rs` — recursive directory upload/download with a live progress bar.
Wired directly into `bin/vela.php` (no dialog needed, matching vela's own F5/F6 behavior):

- `F5` uploads the left panel's marked entries (or the highlighted one if nothing's marked)
  to the current remote directory.
- `F6` downloads the right panel's marked/highlighted entries into the current local directory.

Runs **synchronously** — there's no practical portable threading in PHP, so unlike Rust's
background-thread + `Arc<Mutex<>>` model, the transfer blocks input until it finishes. A
progress callback redraws the block-character progress bar (ported from
`ui/statusbar.rs`'s hand-rolled `█`/`░` bar, throttled to ~20fps) so it doesn't look frozen
meanwhile. `pcntl_fork` is the escalation path if that tradeoff proves annoying in practice.

Verified without a live connection: `TransferBarRenderer` output via `DummyBackend`,
recursive local file counting against a real scratch directory tree, and the
marked/highlighted-entry selection logic (incl. that `..` is never included) via a
reflection-based unit test. A regression pty check confirms the new two-row status area
didn't break normal navigation. Confirmed working end to end against a real server: both
F5 upload and F6 download tested live by hand.

Milestone 6 done: the full dialog system from `ui/dialogs.rs` + `app.rs`'s dialog structs,
plus `main.rs`'s modal-priority key-dispatch chain, now folded into one
`Vela\App::handleKey()` entry point:

- **Help** (`F1`, `Esc`/`q` to close) — static shortcut list.
- **Rename** (`F2`) / **Mkdir** (`F7`) — `Vela\Ui\TextInput`-backed single-field dialogs.
- **Delete** (`F8`) — confirms against marked entries (or the highlighted one), local
  (recursive) or remote.
- **Profile manager** (`F9` / `p`) — list/new/edit/delete profiles in `~/.config/vela/profiles.toml`,
  finally replacing the `--profile=` CLI flag (still there for scripting convenience) as the
  real way to connect. No save-password field on the form yet — that needs OS-keychain
  access, still milestone 8, so password-auth profiles always prompt.
- **Password** and **host-key-verification** dialogs — the connect flow
  (`beginConnect`/`doConnect`) now lives entirely in-app: password prompt for
  password-auth profiles, an accept/reject prompt (with fingerprint) for unrecognized
  host keys, wired to the same `SftpConnection`/`known_hosts` code from milestone 3.
- **Permission-fix** dialog — offered automatically at startup if `profiles.toml` isn't
  mode 0600 (same check as milestone 2's `UnsafePermissionsException`, now with a UI
  instead of just an error).
- **Shell** (`!`) and **tail** (`t`) — run a local shell command (`proc_open`, cwd = left
  panel) or tail the last 50 lines of a selected remote file, sharing one dialog for both
  (input phase vs. output phase, like the Rust version).

Dialogs render as an overlay via a new `Vela\Ui\CenteredBox` widget + `CenteredBoxRenderer`
— php-tui has no `Clear` widget (ratatui's usual way to blank the area under a popup), but
`BlockWidget` always builds a fresh blank sub-buffer for its contents and pastes the whole
thing back, so a bordered dialog Block clears whatever was underneath it as a side effect.
Verified directly with a synthetic "background full of X's" test before building on it.

**On the php-tui/term Esc-reliability investigation**: chasing an intermittently "lost"
Escape keypress across several dialogs turned into the biggest side-quest of this
milestone. The short version — most of what looked like a parser bug was actually a
**testing-harness artifact**: `expect` scripts using blocking `sleep` between keystrokes
don't drain the pty continuously, and `AnsiPainter` writes its ANSI output with one
unbuffered `fwrite()` per queued action (no batching) — under backpressure from a
non-draining reader this can make a single `display->draw()` call block for anywhere from
~250ms to 3+ seconds (measured), long enough for multiple real keystrokes to bunch into one
read and occasionally hit a parser edge case. Switching test scripts to `expect -re`
pattern-matching (which keeps the pty continuously drained, like a real terminal emulator
always does) made the issue disappear entirely, and direct event-log instrumentation
confirmed the app's own key-dispatch logic is correct. The one *confirmed* real upstream
bug — the sticky-`$more` buffer issue fixed in milestone 4 — remains patched and valid,
since byte-bunching can still legitimately happen in real usage (fast typing, network
buffering over SSH, etc.), just far less dramatically than under a stalled test harness.
As cheap extra insurance, the help dialog also accepts `q` (not just `Esc`) to close.

Verified: `DummyBackend` renders of every dialog state (15 combinations, no exceptions);
reflection-based direct tests of `confirmRename`/`confirmMkdir`/`confirmDelete` (incl.
recursive directory deletion) and `runShellCommand` (real `proc_open`, correct cwd, stdout
capture, exit codes) against real scratch files; a ground-truth event-log trace confirming
the F1→close→quit sequence dispatches correctly; and `expect -re`-based interactive pty
sessions for the profile dialog (open → new form → cancel → close) and rename dialog
(open → cancel), all against a scratch `$HOME` so nothing real gets touched.

Live-tested end to end against a real SFTP connection (macOS Remote Login to `localhost`):
connect flow, host-key-verification dialog, and the remote dialogs all confirmed working.

**Fixed after live testing**: typing into `TextInput` fields (and pasting) was noticeably
laggy. Two causes, both fixed: `bin/vela.php`'s main loop now drains all currently-buffered
input events before redrawing, instead of redrawing after every single keystroke (a paste
used to trigger one full redraw per character); and a new Composer patch
(`patches/php-tui-term-ansipainter-buffered-write.patch`) batches `AnsiPainter`'s ANSI
output into one `write()` call per frame instead of one per queued action (cursor move,
color change, etc. — a full repaint can queue thousands of these). See `patches/README.md`
for the measurements behind this.

Milestone 7 done: the theme system from `ui/theme.rs`. `Vela\Theme\Theme` carries the same
~50 named color fields; `ThemeChoice` resolves Auto (via `COLORFGBG`) / Dark / Light /
Custom; `ThemeStore` persists the choice to `~/.config/vela/settings.toml` and loads custom
themes from `~/.config/vela/themes/*.toml` — **using the same snake_case TOML keys the Rust
version writes**, so theme files and the settings file are shared between both
implementations (verified read-only against the real Rust-written `dark.toml`,
`catppuccin-latte.toml`, and `settings.toml` on this machine). Template files
(dark/light/custom) are seeded on first start, never overwritten. `Ctrl+T` cycles
Auto → Dark → Light → each custom theme → Auto and persists the choice; `Ctrl+U`/`Ctrl+S`
swap the panels visually (data model unchanged), both from any mode — same as `main.rs`.
Every renderer (panels, transfer bar, hint area, all 9 dialogs) now takes its colors from
the resolved theme instead of hard-coded values. Verified: 28 dark+light DummyBackend
renders of every dialog state, cycle-order unit tests incl. custom themes, TOML round-trip
tests, real-file interop checks, and a live pty session confirming Ctrl+T cycling +
persistence.

Milestone 8 done: the remaining parity features.

- **F4 — edit in `$EDITOR`** (`prepare_edit`/`launch_editor`/`finish_edit` from `main.rs` +
  `app.rs`): local files open in place; remote files are downloaded to a temp dir first.
  The TUI suspends (leave alt screen, disable raw mode), the editor runs (`$EDITOR` →
  `$VISUAL` → vim → nano → vi, each verified with `command -v`), then the TUI resumes.
  For remote files an mtime comparison decides whether to re-upload — over a **fresh**
  SFTP session, since the old one may have timed out while the editor was open. The temp
  dir is deleted afterwards (Rust gets this from `TempDir` RAII; here it's done by hand).
- **OS-keychain integration** (`Vela\Config\Keychain`): service name `vela`, account =
  profile name — same scheme as the Rust `keyring` crate, so passwords saved by either
  implementation are readable by the other. Shells out to macOS `security` (or Linux
  `secret-tool`) since PHP has no native keychain binding; note that
  `security add-generic-password` briefly exposes the password in the process list, a
  prototype-grade tradeoff documented in the class. The profile form gained the
  save-password toggle and masked password field (same visibility rules as Rust:
  password-auth only, field only when the toggle is on), profile save/edit/delete manage
  the keychain entry, and connect uses the saved password automatically instead of
  prompting (`begin_connect`'s fast path).
- **F10** now also quits, matching `handle_main_key`.

Verified: real macOS-keychain round trip (save/load/overwrite/delete/double-delete) with a
throwaway account name; form-visibility and tab-cycle unit tests for the two new fields;
finishEdit mtime/cleanup logic against real temp files; masked rendering (plaintext never
appears in the frame buffer); and a live pty session pressing F4 with `EDITOR=true`
confirming the suspend/resume handoff end to end.

Statusbar done (the last planned piece): `Render::buildHintArea()` now ports
`statusbar.rs`'s `render_hint_bar()` — a badge-style function-key row (`F1 Help` … `F10
Quit`, plus `! Shell` and `^U Swap`) instead of the interim plain-text hint line. `F3
Disconnect` appears only while connected, with the danger-colored badge; row two shows the
status message. Colors all come from the theme (`hint_badge_*`, `hint_label`,
`status_message`, `hint_bar_bg`). Verified via DummyBackend (badge presence, F3
hidden/shown, status row) and a live pty session confirming the bar renders and F10 quits.

Milestone 9a done: PHAR packaging via `humbug/box`. Build with:

```
composer install
composer phar
./vela.phar --profile="Lokal"
```

`box.json` uses Box's auto-discovery (no explicit `directories`/`finder` list) — it reads
`composer.json`/`composer.lock` to find what to bundle and, via Composer's
`dev-package-names` metadata, automatically excludes dev-only dependencies (`humbug/box`
itself, `cweagans/composer-patches`) without needing a separate `composer install --no-dev`
pass. Verified by inspecting `box compile --debug`'s file dump: only `bin/vela.php` (the
declared entry point — other scripts that used to live in `bin/` weren't pulled in either),
`src/`, and the four production vendor packages end up in the 906KB, 769-file PHAR; the earlier
`composer-patches` fixes are included as-is since they'd already been applied to the files
on disk before packaging. `./vela.phar` runs directly (Box adds a `#!/usr/bin/env php`
shebang + sets it executable) — no `php` prefix needed, same UX as the Rust binaries.

Verified end to end: `php -l` on the built PHAR, the `--profile=` CLI error path, a
headless (non-tty) boot showing the same graceful `stty` failure as the unpackaged version
(confirms `phar://`-relative path resolution works correctly for `__DIR__`-based requires),
and a full live pty cycle (render → F1 help → Esc close → q quit) — byte-identical
interactive behavior to running `bin/vela.php` directly from source. `vela.phar` itself
isn't committed (build artifact, regenerate with `composer phar`); ignored via `.gitignore`.

Milestone 9b (standalone static binary via `static-php-cli`) is next.

Milestone 9b done: standalone static binary (arm64) via `static-php-cli` (spc). Build with:

```
bin/build-static.sh
./vela-php-arm64 --profile="Lokal"
```

`bin/build-static.sh` downloads `spc` (static-php-cli's own prebuilt binary — its working
directory defaults to `~/.local/share/vela-php-spc`, outside this repo, override with
`SPC_HOME`; it's ~2GB of PHP source + build products, not something to keep inside a git
checkout), runs `spc doctor --auto-fix` (installed `automake`/`cmake`/`bison` via Homebrew
on this machine — standard reversible dev tooling, same category as the spike phase's
`brew install php composer`), downloads PHP 8.5 + library sources for the extension set the
app actually needs, compiles a fully static PHP with those extensions built in plus the
`micro` SAPI (~3 minutes), rebuilds `vela.phar` fresh, then combines `micro.sfx` + the phar
into one Mach-O binary — same idea as vela's own `vela-arm64` Rust binary.

**Extension set** (`mbstring,ctype,openssl,gmp,sodium,phar,zlib,filter`) was derived by
grepping our own code and the three production vendor packages for extension-specific
function calls (`mb_*`/`ctype_*` are hard dependencies of php-tui and our own port; phpseclib
conditionally uses `openssl_*`/`gmp_*`/`sodium_*`/`bcmath` depending on what's
available — included openssl+gmp+sodium for speed and to cover modern ed25519 keys, skipped
bcmath since gmp already covers the big-integer arithmetic; `phar`+`zlib` are needed because
the phar payload itself is gzip-compressed and read through the phar stream wrapper at
runtime). `spc`'s own `dump-extensions` auto-detector came up empty — it goes off declared
`ext-*` entries in `composer.json`, which we don't have, rather than scanning code — so this
list is derived by hand instead of tool-generated.

Verified: `otool -L` shows only `libSystem`/`libresolv` (both always present on macOS — no
PHP, Homebrew, or other runtime dependency); ran with `PATH=/usr/bin:/bin` (Homebrew, and
therefore `php`/`composer`, unreachable) for the CLI-flag error path, a headless non-tty
boot, and a full live pty cycle (render → help open/close → quit) — all identical to the
PHAR/source behavior. A standalone functional check (via the uncombined `micro.sfx`, same
build) round-tripped AES via `openssl_encrypt`/`decrypt`, verified big-integer arithmetic
via `gmp_add`, and did an ed25519 sign/verify via `sodium_crypto_sign_*` — confirming the
statically-linked crypto extensions aren't just present but actually produce correct
output (caught and fixed one bug in the test itself along the way: a mistyped expected
sum, not a build issue — cross-checked against Python's arbitrary-precision arithmetic).
Re-ran the full `bin/build-static.sh` end to end a second time to confirm the build is
reproducible (byte-identical size, same minimal linkage, same passing tests).

Not committed (17MB build artifact, platform-specific, regenerate with `bin/build-static.sh`)
— ignored via `.gitignore` alongside room for future `x86_64`/`universal` variants if
that's ever revisited.

Testing / quality tooling — PHPUnit is set up as the first of three planned quality tools
(PHPUnit → PHPStan → Rector, being introduced one at a time). Run with:

```
composer test
```

Starter set converts earlier ad-hoc scratch-script verification into checked-in tests:
`tests/Theme/ThemeTest.php` (TOML round-trip, snake_case key interop with the Rust theme
files), `tests/Ui/TextInputTest.php` (cursor logic incl. multi-byte/emoji edge cases via a
`#[DataProvider]`), `tests/Config/ProfileStoreTest.php` (save/load round-trip, 0600
permission enforcement, `$_SERVER['HOME']`-isolated via `setUp()`/`tearDown()` so it never
touches a real `~/.config/vela/profiles.toml`), and `tests/Transfer/TransferEngineTest.php`
(`countLocalFiles()` only — `uploadBatch`/`downloadBatch`/`countRemoteFiles` need a real
`SftpConnection`, which is `final` with a private constructor, so they stay covered by live
manual testing instead, same as everywhere else in this project). 35 tests, 59 assertions,
all passing.

PHPStan (second of the three planned quality tools) started at **level 6** and is being
ratcheted up step by step — deliberately gradual rather than jumping straight to php-tui's
own `level: max` + `strictRules`, since this is a much younger codebase and each level tends
to surface a genuinely different category of issue. Currently at **`max`** (all levels, 0-9
plus PHPStan 2.x's `max` = 10). Run with:

```
composer phpstan
```

Fixed all 11 findings from the first run (level 6), each a real issue rather than noise:

- **Three top-level `bin/*.php` scripts each declared a global `function run(...)`** with
  different signatures — harmless only because they're never `require`'d together in the
  same process, but PHPStan (analyzing all of `bin/` at once) correctly flagged the
  resulting cross-file ambiguity. Renamed each uniquely (`run_spike`, `run_textinput_demo`,
  `run_vela`) to remove the collision at its root instead of just working around the two
  call sites that happened to get flagged.
- **A redundant `instanceof` check** in `App::handleProfileListKey()` — PHPStan's flow
  analysis proved that after two earlier `if`-blocks each `return` on their own branch of a
  `CharKeyEvent|CodedKeyEvent|FunctionKeyEvent` union, the only type left by that point
  *is* `FunctionKeyEvent`, making the check dead. Simplified accordingly.
- **Missing `@return Type[]`/`@param array<string,mixed>` PHPDoc** on several
  `SftpConnection` methods that return `array`-typed values without saying what's inside —
  added precise annotations (`FileEntry[]`, `string[]`) throughout.
- **A dead `!== []` guard** in `tailRemoteFile()` — `explode()` on a non-empty separator
  can never return an empty array (even `""` explodes to `['']`), so the check was
  unreachable; removed it and left a comment explaining why.
- **A real type gap in `authenticate()`**: `PublicKeyLoader::load()` can return either a
  `PrivateKey` or a `PublicKey` depending on what's actually in the file, but `login()`
  needs the private half to sign the handshake. A `key_path` accidentally pointing at
  `id_rsa.pub` would previously have failed confusingly deep inside phpseclib. Added an
  explicit `instanceof PrivateKey` check with a new `NotAPrivateKeyException` for a clear,
  actionable error instead.

All 35 PHPUnit tests and a live pty smoke test still pass after the fixes.

Ratcheted to **level 7** next. 5 findings, all fixed:

- `Keychain::run()`'s `$argv` param was typed `string[]`, but `proc_open()` specifically
  needs a `list<string>` (sequential integer keys) when given an array command — a plain
  `string[]` doesn't guarantee that. Narrowed the PHPDoc type with a comment explaining why.
- `tailRemoteFile()` passed phpseclib's `$this->sftp->get($path)` straight into `explode()`,
  but `get()` is polymorphically typed `string|bool` (the actual return type secretly depends
  on the unused `$local_file` parameter) — fixed with an `is_string()` check instead of the
  previous `=== false` comparison, which only handled one half of the non-string case.
- `authenticate()` passed an unchecked `file_get_contents($keyPath)` straight into
  `PublicKeyLoader::load()`, which accepts `string`, not `string|false` — added an explicit
  false-check that throws a clear `SftpException` naming the unreadable path. A genuine
  TOCTOU-race robustness improvement, not just a type-checker appeasement.
- The same unchecked-`file_get_contents()` pattern in `ProfileStoreTest`'s own round-trip
  test — fixed with `self::assertIsString($rawToml)` before the string-assertions that
  followed, which is both correct type-narrowing *and* a better test (a failed read now
  fails with a clear message instead of a confusing "expected string, got false" further
  down).

Ratcheted to **level 8** next. 120 findings, 119 of them in `App.php` — but all one
underlying pattern, not 120 separate bugs: dialog-handler methods (`handleRenameDialogKey`,
`confirmMkdir`, `handleProfileFormKey`, etc.) read nullable properties like `$this->sftp` or
`$this->renameDialog` that `handleKey()`'s dispatch chain has already null-checked before
calling them — an invariant PHPStan can't see across the method-call boundary, since from its
perspective the property could have changed by the time the callee reads it again. Fixed by
threading the already-checked value through as an explicit non-nullable parameter instead of
re-reading the property (`handleRenameDialogKey(..., RenameDialog $dlg)` rather than
`$dlg = $this->renameDialog` inside the method) — mechanically repeated across all 8 dialog
clusters (rename, mkdir, delete, password, host-key, permission, shell, profile incl. its
list/form/confirm-delete sub-dispatch). A related variant showed up in closures passed to
`tryRun()`: PHPStan's null-narrowing doesn't reliably survive a closure boundary either, so
`enterActive()`, `uploadActive()`, `downloadActive()`, `goUpActive()`, and `finishEdit()` each
snapshot `$this->sftp` to a local `$sftp` variable before the closure instead of reading
`$this->sftp` (or worse, `$this->sftp->something()`) from inside it. The 1 remaining finding
was the same pattern in miniature in `ShellDialogRenderer::build()`: it passed `$dlg` into
`buildOutput()` after checking `$dlg->output !== null`, but `buildOutput()` re-read
`$dlg->output` itself — fixed by passing the already-null-checked array directly.

All 35 PHPUnit tests, PHPStan level 8 (0 errors), and a live pty smoke test (incl. exercising
the refactored profile-dialog cluster: open, create, list, delete) still pass after the
fixes.

Ratcheted to **level 9** next. 30 findings, all one theme: `mixed`. Level 9 stops trusting
values PHPStan can't statically prove the type of — `$_SERVER['HOME']`, TOML-parsed data,
phpseclib return values — even when a nearby `??`/`?:` fallback makes the value obviously a
string at runtime. Two concrete fixes, then the same pattern repeated everywhere else:

- The five `$_SERVER['HOME'] ?? (getenv('HOME') ?: $fallback)` call sites (`App.php` ×2,
  `ProfileStore`, `SftpConnection`, `ThemeStore`, `bin/vela.php`) all had the same gap: `??`
  only excludes `null`, so the combined expression is still typed `mixed`, not `string`.
  Replaced each with an explicit `is_string($home) && $home !== ''` check.
- `Profile::fromArray()` blindly cast every TOML field with `(string)`/`(int)` — technically
  "fixable" by casting away the error, which is exactly what the instructions say not to do,
  and for good reason: a blind cast on a corrupted field (e.g. a stray `[[sub_table]]` where
  a plain value belongs) would silently turn an array into the literal string `"Array"`
  instead of surfacing the problem. Replaced with `stringOr()`/`intOr()`/`nullableString()`
  helpers that fall back to a default for any non-scalar value instead of coercing it blindly
  — a real robustness improvement for a hand-editable config file, not just a type-checker
  appeasement.
- The same `mixed`-from-untyped-source shape recurred in `bin/vela.php` (`$_SERVER['argv']`
  → switched to the properly-typed global `$argv`), `SftpConnection` (phpseclib stat/realpath
  results), `Theme` (`get_object_vars($this)` loses the fact that every property is
  `AnsiColor`), and the test's own `$_SERVER['HOME']` snapshot.

Ratcheted to **max** next (PHPStan 2.x's `max` is level 10, one past `level: 9` — the same
identifier php-tui itself uses, so this now matches its strictness exactly). 9 findings:

- More of the same mixed-from-TOML shape, one level deeper: `array_map`/`foreach` over
  TOML-parsed data down to individual `array<string,mixed>` **entries** (a profile table, an
  SFTP stat result) still wasn't provably string-keyed, just provably an array. Added a small
  `stringKeyed()` helper (one per class, mirroring `Profile`'s existing per-class-helper
  style rather than inventing a shared utility for three call sites) that rebuilds the array
  while checking each key with `is_string()`, since a blind `is_array()` check alone doesn't
  prove the key type PHPStan needs.
- `DeleteDialog`'s constructor wanted `array<int, array{name:string,isDir:bool}>` but got
  `non-empty-array<array{...}>` from `array_map()` — traced back to `entriesToTransfer()`'s
  `@return FileEntry[]` annotation, which (surprising, and true of every other `Type[]`
  annotation in this codebase) only promises `array<int|string, Type>`, **not** `array<int,
  Type>` — the shorthand doesn't guarantee integer keys the way it reads. Since the array is
  always built via `$entries[] = ...` (genuinely a list), retyped it — and by the same
  reasoning, `PanelState::$entries` and all four `SftpConnection` listing methods — to the
  precise `list<FileEntry>`, which finally satisfied `DeleteDialog` without loosening
  anything.
- `PanelState::markAll()`'s `$this->marked[$i] = true` inside a loop couldn't be typed
  precisely as `array<int,true>` no matter how the loop was shaped (confirmed with PHPStan's
  own `dumpType()` debug helper: `$i` was `int|string`, not `int`, exactly because of the
  `Type[]`-annotation gap above). Simplified by building the replacement array in one
  expression instead of mutating the property key-by-key — a genuine readability win, not
  just a type-checker workaround.

All 35 PHPUnit tests, PHPStan `max` (0 errors), and a live pty smoke test (mark-all/unmark-all
toggle, which the `PanelState` change touches directly) still pass.

**Rector** (third and last of the three planned quality tools) is set up next, config at
`rector.php` mirroring php-tui's own (`vendor/php-tui/php-tui/rector.php`) as closely as this
project's layout allows: same `SetList::CODE_QUALITY` + `SetList::TYPE_DECLARATION` +
`LevelSetList::UP_TO_PHP_81` sets (matching this project's own `composer.json` PHP
constraint), same `importNames()`/`importShortClasses()`, same skip list. Run with:

```
composer rector          # applies changes
composer rector:dry      # preview only, no changes written
```

Two deliberate deviations from php-tui's config, decided via a dry-run review rather than
copied blindly — php-tui's config also predates Rector 2.x, so `StaticClosureRector` and
`StaticArrowFunctionRector` (both explicit rules in its config) had to be dropped outright:
they're deprecated in Rector 2.5.7 and crash instead of skipping cleanly.

- `EncapsedStringsToSprintfRector` would have rewritten ~23 spots, turning `"Text {$var}"`
  into `sprintf('Text %s', $var)` across nearly every UI/exception string in this codebase,
  including all the German status messages. Skipped: this project already uses string
  interpolation consistently everywhere, and `sprintf()` is strictly more verbose for these
  short, simple substitutions — this would fight the established style, not improve it.
- `LocallyCalledStaticMethodToNonStaticRector` (part of `SetList::CODE_QUALITY`, not
  something php-tui's own config even mentions) would have converted 4 stateless private
  static helpers (`joinPath`, `parentOf`, `formatPermissions`, `fileEntryFromStat`/
  `stringKeyed` in `SftpConnection`, `entriesToTransfer`/`expandTildeLocal`/`nextTheme` in
  `App`) to instance methods called via `$this->`. Skipped: `private static` on a method that
  doesn't touch object state is a deliberate, existing convention in this codebase, not an
  oversight — converting it away is a style regression.

What was left after skipping both (5 files) were genuine, no-downside modernizations:
`importNames()` replacing fully-qualified class references (`\RuntimeException` →
`RuntimeException` with a `use` import, likewise for `PhpTui\Tui\Display\Display` in
`bin/vela.php`), constructor-property promotion in `TextInput`, a first-class-callable
conversion (`static fn (int $f) => $form->isFieldVisible($f)` → `$form->isFieldVisible(...)`),
and a negated-ternary flip in `ProfileDialogRenderer`. Applied; verified with `composer
phpstan` (0 errors), `composer test` (35 tests, 59 assertions), and a live pty smoke test of
the profile dialog (the two changed UI files).

With PHPUnit, PHPStan (`max`), and Rector all in place, the three-tool tooling initiative
this section has been tracking is complete.
