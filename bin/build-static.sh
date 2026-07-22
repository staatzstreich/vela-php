#!/usr/bin/env bash
set -euo pipefail

# Builds a standalone, self-contained vela-php binary via static-php-cli
# (spc): a full PHP 8.5 interpreter with mbstring, ctype, openssl, gmp,
# sodium, phar, zlib and filter statically linked in, combined with our own
# vela.phar payload into a single self-contained executable that needs no
# PHP installation on the target machine — same idea as vela's own
# vela-arm64/vela-x86_64/vela-universal Rust binaries.
#
# Auto-detects the host OS/architecture it's run ON, so this script is
# meant to be cloned and run natively on whichever machine you have —
# macOS (arm64 or x86_64) or Linux (x86_64 or aarch64) — rather than
# cross-compiled from one machine for another: spc explicitly does not
# support macOS cross-compilation or universal binaries ("Currently we do
# not support universal and cross-compilation for macOS",
# https://static-php.dev/en/guide/env-vars.html). If you have access to
# both a macOS arm64 and x86_64 machine and want a universal binary like
# vela's, build on each natively with this script, then combine the two
# results yourself afterwards (spc doesn't do this step either):
#   lipo -create vela-php-arm64 vela-php-x86_64 -output vela-php-universal
#
# Linux support is new and NOT personally verified on real Linux hardware
# (no Linux test machine available while writing this) — please open an
# issue/PR if you hit problems. It should work: spc defaults to a
# musl-linked, fully static binary on Linux (SPC_LIBC=musl is the
# documented default), which is portable across distros and — unlike a
# statically-linked glibc — doesn't have glibc's known NSS/getaddrinfo
# static-linking gotcha, so SFTP host resolution should work out of the
# box without any extra flags.
#
# spc's own working directory (PHP source, build products, ~2GB) lives
# outside this repo by design — override SPC_HOME to relocate it.
#
# Usage: bin/build-static.sh

SPC_HOME="${SPC_HOME:-$HOME/.local/share/vela-php-spc}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXTENSIONS="mbstring,ctype,openssl,gmp,sodium,phar,zlib,filter"

HOST_OS="$(uname -s)"
HOST_ARCH="$(uname -m)"

case "$HOST_OS" in
    Darwin)
        case "$HOST_ARCH" in
            arm64) SPC_DOWNLOAD="spc-macos-aarch64"; OUTPUT_NAME="vela-php-arm64" ;;
            x86_64) SPC_DOWNLOAD="spc-macos-x86_64"; OUTPUT_NAME="vela-php-x86_64" ;;
            *) echo "Unsupported macOS architecture: $HOST_ARCH" >&2; exit 1 ;;
        esac
        ;;
    Linux)
        case "$HOST_ARCH" in
            x86_64) SPC_DOWNLOAD="spc-linux-x86_64"; OUTPUT_NAME="vela-php-linux-x86_64" ;;
            aarch64) SPC_DOWNLOAD="spc-linux-aarch64"; OUTPUT_NAME="vela-php-linux-aarch64" ;;
            *) echo "Unsupported Linux architecture: $HOST_ARCH" >&2; exit 1 ;;
        esac
        ;;
    *)
        echo "Unsupported OS: $HOST_OS (this script supports macOS and Linux only)" >&2
        exit 1
        ;;
esac

mkdir -p "$SPC_HOME"
cd "$SPC_HOME"

if [ ! -x ./spc ]; then
    echo "==> Downloading static-php-cli ($SPC_DOWNLOAD)"
    curl -fsSL -o spc "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/$SPC_DOWNLOAD"
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
"$SPC_HOME/spc" micro:combine vela.phar -M "$SPC_HOME/buildroot/bin/micro.sfx" -O "$OUTPUT_NAME"

echo "==> Done: $PROJECT_DIR/$OUTPUT_NAME"
file "$OUTPUT_NAME"
if [ "$HOST_OS" = "Darwin" ]; then
    otool -L "$OUTPUT_NAME"
else
    ldd "$OUTPUT_NAME" 2>&1 || echo "(not a dynamic executable — expected for a fully static musl binary)"
fi
