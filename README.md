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
