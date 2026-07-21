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

**Known php-tui/term quirk to watch for in milestone 6** (dialogs cancel via Escape): after
two or more arrow-key presses followed by a character key, the *next* bare Escape keypress
can get silently dropped by php-tui/term's event parser — a second Escape right after
always gets through. Reproduced reliably via scripted pty input; root cause looks like
stale buffer state in `EventParser`, not something in our own code. No workaround applied
yet since it doesn't block anything today — worth a mitigation (or an upstream report) once
dialogs actually depend on Escape-to-cancel.
