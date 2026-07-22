#!/usr/bin/env bash
set -euo pipefail

# Builds a standalone, self-contained vela-php-arm64 binary via
# static-php-cli (spc): a full PHP 8.5 interpreter with mbstring, ctype,
# openssl, gmp, sodium, phar, zlib and filter statically linked in, combined
# with our own vela.phar payload into a single Mach-O executable that needs
# no PHP installation on the target machine — same idea as vela's own
# vela-arm64/vela-x86_64/vela-universal Rust binaries.
#
# spc's own working directory (PHP source, build products, ~2GB) lives
# outside this repo by design — override SPC_HOME to relocate it.
#
# Usage: bin/build-static.sh

SPC_HOME="${SPC_HOME:-$HOME/.local/share/vela-php-spc}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXTENSIONS="mbstring,ctype,openssl,gmp,sodium,phar,zlib,filter"

mkdir -p "$SPC_HOME"
cd "$SPC_HOME"

if [ ! -x ./spc ]; then
    echo "==> Downloading static-php-cli"
    curl -fsSL -o spc https://dl.static-php.dev/static-php-cli/spc-bin/nightly/spc-macos-aarch64
    chmod +x spc
fi

echo "==> Checking build prerequisites (spc doctor)"
./spc doctor --auto-fix

echo "==> Downloading PHP source + library sources for: $EXTENSIONS"
./spc download --for-extensions="$EXTENSIONS" --with-php=8.5

echo "==> Building static PHP + micro SAPI (this compiles PHP from source, ~3 min)"
./spc build "$EXTENSIONS" --build-micro --with-micro-fake-cli

echo "==> Rebuilding vela.phar"
cd "$PROJECT_DIR"
composer phar

echo "==> Combining micro.sfx + vela.phar into a standalone binary"
"$SPC_HOME/spc" micro:combine vela.phar -M "$SPC_HOME/buildroot/bin/micro.sfx" -O vela-php-arm64

echo "==> Done: $PROJECT_DIR/vela-php-arm64"
file vela-php-arm64
otool -L vela-php-arm64
