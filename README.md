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
connections. Not wired into the UI yet — that lands with SFTP connect (milestone 3) and
the profile dialog (milestone 6).
