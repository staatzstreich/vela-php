# vela-php

Feasibility spike: exploring whether [vela](https://github.com/) (a Rust/ratatui dual-panel
SFTP TUI client) can be rebuilt in PHP using [php-tui/php-tui](https://github.com/php-tui/php-tui).

Status: spike phase — proving that php-tui's terminal backend (raw mode, rendering, key input)
works reliably on macOS before porting any real vela functionality. See `bin/spike.php`.
