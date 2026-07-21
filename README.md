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
didn't break normal navigation. The actual network transfer (F5/F6 against a real server)
needs a live SFTP session, which — like milestone 3 — needs manual testing in a real
terminal.
