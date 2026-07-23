# Verification environment for bin/build-static.sh's Linux path — not a
# runtime/deployment image. Lets anyone reproduce the Linux static-binary
# build (and confirm it actually works) without native Linux hardware, via
# Docker's --platform flag (linux/arm64 runs natively on an Apple Silicon
# host; linux/amd64 runs under QEMU emulation — slower to compile, ~10 min
# instead of ~1-2 min, but still a genuine, working x86_64 ELF binary).
#
# Usage (from the repo root):
#   docker build --platform linux/arm64 -f docker/linux-build-test.Dockerfile -t vela-php-build-test .
#   docker run --rm --platform linux/arm64 -v "$PWD":/repo -w /repo vela-php-build-test \
#     bash -c "composer install --no-interaction --quiet && bash bin/build-static.sh"
#
# Swap linux/arm64 for linux/amd64 to build+verify the other architecture.
# The resulting vela-php-linux-{aarch64,x86_64} binary and vela.phar land in
# the mounted repo directory (both already .gitignore'd) — try it with
# `PATH= ./vela-php-linux-aarch64 --profile=DoesNotExist` (should print
# "Unknown profile: ..." and exit 1) or a real pty session via `expect`.

FROM php:8.4-cli-bookworm

# libicu-dev: build dependency for ext-intl, which php-tui/php-tui requires.
# The rest (bison, flex, cmake, ...) is installed at container-run time by
# spc doctor --auto-fix itself, matching how bin/build-static.sh already
# handles this on macOS via Homebrew.
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl git unzip libicu-dev \
    && docker-php-ext-install intl

RUN curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /repo
