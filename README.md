# vela-php

Feasibility spike: exploring whether [vela](https://github.com/) (a Rust/ratatui dual-panel
SFTP TUI client) can be rebuilt in PHP using [php-tui/php-tui](https://github.com/php-tui/php-tui).

Status: spike passed (see `bin/spike.php`) — php-tui's terminal backend works reliably on macOS.

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
cursor, with an optional masked mode for password fields. Try it standalone with:

```
php bin/textinput-demo.php
```

`←`/`→`/`Home`/`End` move the cursor, `Backspace`/`Delete` edit, `Tab` toggles masked
rendering, `Esc` quits. Verified: unit tests (incl. emoji/umlaut mid-string edits) and a
real pty session for typing/arrows/backspace/delete.

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
declared entry point — `spike.php`/`textinput-demo.php` are not pulled in), `src/`, and the
four production vendor packages end up in the 906KB, 769-file PHAR; the earlier
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
