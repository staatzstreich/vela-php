#!/usr/bin/env bash
set -euo pipefail

# Builds a standalone, self-contained vela-php binary via static-php-cli
# (spc): a full PHP 8.5 interpreter with mbstring, ctype, openssl, gmp,
# sodium, phar, zlib, filter, imagick, json and curl statically linked in,
# combined with our own vela.phar payload into a single self-contained
# executable that needs no PHP installation on the target machine — same
# idea as vela's own vela-arm64/vela-x86_64/vela-universal Rust binaries.
# (json/curl are only there so this script's own build step can run
# composer.phar/box through spc's static php CLI, see below — harmless
# extras in the shipped binary too.)
#
# imagick (for the image-preview 'v' key) is confirmed supported by spc,
# including the micro SAPI this script builds (`spc dev:extensions`), but it
# drags in a long static dependency chain (libjpeg, libpng, libwebp, libjxl,
# freetype, libtiff, libde265, libaom, libheif, imagemagick itself), so
# expect a noticeably longer build and a larger binary than before this
# extension was added. The app works fine without it either way — php-tui
# shows a graceful placeholder when Imagick isn't available.
#
# No host PHP or Composer required (not even via Homebrew): spc itself
# ships as a self-contained static binary, and this script uses spc to
# build its own static `php` CLI SAPI too, then runs a portable
# composer.phar and vendor/bin/box directly through that binary. Every
# PHP-shaped tool this script touches (spc, php, composer, box) is
# either downloaded as a standalone executable/phar or built by spc —
# nothing is resolved via $PATH.
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
# Linux support verified via Docker (no native Linux hardware needed — see
# docker/linux-build-test.Dockerfile) on both aarch64 (native under Docker
# Desktop on Apple Silicon) and x86_64 (QEMU-emulated, ~10x slower to
# compile but produces a genuinely working binary): both built a real static
# musl-linked ELF binary (spc's own default on Linux, SPC_LIBC=musl — no
# glibc NSS/getaddrinfo static-linking gotcha to work around) that rendered
# the TUI correctly under a real pty session and exited cleanly.
#
# spc's own working directory (PHP source, build products, ~2GB) lives
# outside this repo by design — override SPC_HOME to relocate it.
#
# Usage: bin/build-static.sh

SPC_HOME="${SPC_HOME:-$HOME/.local/share/vela-php-spc}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXTENSIONS="mbstring,ctype,openssl,gmp,sodium,phar,zlib,filter,imagick,json,curl,tokenizer,dom,xml,xmlwriter,libxml"
# json/curl/tokenizer/dom/xml aren't needed by vela-php itself, but are
# needed to run composer.phar and vendor/bin/box (see below) through spc's
# static `php` CLI SAPI: json is a hard Composer requirement, curl lets
# Composer download packages without falling back to (slower,
# proxy-unfriendly) php:// stream wrappers, and tokenizer/dom/xml are
# required by our require-dev packages (rector needs tokenizer, phpunit
# needs dom) which composer install (deliberately run WITH dev deps, see
# below) has to resolve.
COMPOSER_PHAR="$SPC_HOME/composer.phar"

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

echo "==> Building static PHP CLI + micro SAPI (this compiles PHP from source, ~3 min)"
./spc build "$EXTENSIONS" --build-cli --build-micro --with-micro-fake-cli
STATIC_PHP="$SPC_HOME/buildroot/bin/php"

if [ ! -f "$COMPOSER_PHAR" ]; then
    echo "==> Downloading a portable composer.phar"
    curl -fsSL -o "$COMPOSER_PHAR" "https://getcomposer.org/composer.phar"
fi

# box compile does its own separate "is Composer available" check
# (ComposerProcessFactory) and wants a single executable path for it, not
# a two-part "php composer.phar" invocation — so give it a tiny wrapper
# script instead of trying to make composer.phar itself executable.
COMPOSER_BIN="$SPC_HOME/composer-bin.sh"
cat > "$COMPOSER_BIN" <<EOF
#!/bin/sh
exec "$STATIC_PHP" "$COMPOSER_PHAR" "\$@"
EOF
chmod +x "$COMPOSER_BIN"

echo "==> Installing composer dependencies (applies our patches/ via cweagans/composer-patches)"
cd "$PROJECT_DIR"
"$STATIC_PHP" "$COMPOSER_PHAR" install

echo "==> Rebuilding vela.phar"
"$STATIC_PHP" vendor/bin/box compile --composer-bin="$COMPOSER_BIN"

echo "==> Combining micro.sfx + vela.phar into a standalone binary"
"$SPC_HOME/spc" micro:combine vela.phar -M "$SPC_HOME/buildroot/bin/micro.sfx" -O "$OUTPUT_NAME"

echo "==> Done: $PROJECT_DIR/$OUTPUT_NAME"
file "$OUTPUT_NAME"
if [ "$HOST_OS" = "Darwin" ]; then
    otool -L "$OUTPUT_NAME"
else
    ldd "$OUTPUT_NAME" 2>&1 || echo "(not a dynamic executable — expected for a fully static musl binary)"
fi
