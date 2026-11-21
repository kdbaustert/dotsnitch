#!/usr/bin/env bash
# Builds builds/dotsnitch-<arch>: the PHAR joined to a static PHP runtime, so the Mac running it
# needs no PHP. The runtime is cached in builds/spc and rebuilt only when a pin or the extension
# list changes.
set -euo pipefail

# Pinned so two builds of the same commit match. Bump the checksums with the versions.
SPC_VERSION=2.8.5
SPC_SHA256_AARCH64=acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225
SPC_SHA256_X86_64=e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a
PHP_VERSION=8.5.13
PHP_SHA256=607602acaf0c3061818e9916ec44a058e8b3d43959a83862b9cd3ec7bf5b785f

root=$(cd "$(dirname "$0")/.." && pwd)
builds="$root/builds"
spc_dir="$builds/spc"
arch=$(uname -m)
binary="$builds/dotsnitch-$arch"
# A clang found outside /usr/bin, such as Xcode's toolchain folder on PATH, can't find the SDK
# on its own, and PHP's configure then fails with "library 'System' not found".
export SDKROOT="${SDKROOT:-$(xcrun --show-sdk-path)}"
extensions=$(grep -v -e '^#' -e '^[[:space:]]*$' "$root/scripts/extensions.txt" | paste -sd, -)

case "$arch" in
    arm64) spc_arch=aarch64 spc_sha256=$SPC_SHA256_AARCH64 ;;
    x86_64) spc_arch=x86_64 spc_sha256=$SPC_SHA256_X86_64 ;;
    *) echo "No static-php-cli build for $arch" >&2 && exit 1 ;;
esac

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$spc_dir"

echo "==> Copying the source without dev packages"
# Untracked files come along so a local build has new files you haven't added yet. A deleted
# file git still tracks is left out, since rsync stops at a name it can't find.
git -C "$root" ls-files -z -co --exclude-standard |
    while IFS= read -r -d '' file; do
        if [[ -e "$root/$file" ]]; then printf '%s\0' "$file"; fi
    done |
    rsync -a --from0 --files-from=- "$root/" "$stage/"
composer install --working-dir="$stage" --no-dev --optimize-autoloader --no-interaction --quiet

echo "==> Building the PHAR"
# A release sets DOTSNITCH_VERSION from its tag, so --version matches the release.
version=${DOTSNITCH_VERSION:-$(git -C "$root" describe --tags --always 2>/dev/null || echo unreleased)}
# app:build is a development command, so it's hidden unless the environment says otherwise.
(cd "$stage" && php dotsnitch app:build dotsnitch --build-version="$version" --env=development --no-interaction)
cp "$stage/builds/dotsnitch" "$builds/dotsnitch.phar"

if [[ "$(cat "$spc_dir/spc.version" 2>/dev/null)" != "$SPC_VERSION" ]]; then
    echo "==> Downloading static-php-cli $SPC_VERSION"
    tarball="$spc_dir/spc-$SPC_VERSION.tar.gz"
    curl -fsSL -o "$tarball" "https://github.com/crazywhalecc/static-php-cli/releases/download/$SPC_VERSION/spc-macos-$spc_arch.tar.gz"
    echo "$spc_sha256  $tarball" | shasum -a 256 -c --quiet
    tar -xzf "$tarball" -C "$spc_dir" spc
    rm "$tarball"
    echo "$SPC_VERSION" > "$spc_dir/spc.version"
fi

micro_key="spc $SPC_VERSION, PHP $PHP_VERSION, $extensions"

if [[ "$(cat "$spc_dir/micro.key" 2>/dev/null)" != "$micro_key" ]]; then
    echo "==> Building the static PHP runtime ($micro_key)"
    rm -rf "$spc_dir/buildroot" "$spc_dir/source" "$spc_dir/micro.key"
    (cd "$spc_dir" && ./spc download --for-extensions="$extensions" --with-php="${PHP_VERSION%.*}" \
        --custom-url="php-src:https://www.php.net/distributions/php-$PHP_VERSION.tar.xz" --prefer-pre-built --no-interaction)
    echo "$PHP_SHA256  $spc_dir/downloads/php-$PHP_VERSION.tar.xz" | shasum -a 256 -c --quiet
    # spc looks for its own pkg-config in builds/spc/pkgroot, not the one on PATH.
    (cd "$spc_dir" && ./spc install-pkg pkg-config --no-interaction)
    (cd "$spc_dir" && ./spc build "$extensions" --build-micro --no-interaction)
    echo "$micro_key" > "$spc_dir/micro.key"
fi

echo "==> Joining the runtime and the PHAR"
# The binary never reads a php.ini from the Mac, so its settings go in here.
(cd "$spc_dir" && ./spc micro:combine "$builds/dotsnitch.phar" --with-micro="$spc_dir/buildroot/bin/micro.sfx" --output="$binary" \
    -I "memory_limit=512M" -I "display_errors=stderr" -I "error_reporting=E_ALL & ~E_DEPRECATED" --no-interaction)

echo "==> Checking it links only against macOS"
# Anything under /opt/homebrew or /usr/local leaked in from this Mac and won't be on others.
leaked=$(otool -L "$binary" | tail -n +2 | awk '{print $1}' | grep -v -e '^/usr/lib/' -e '^/System/Library/' || true)
if [[ -n "$leaked" ]]; then
    echo "$binary links against libraries a fresh Mac doesn't have:" >&2
    echo "$leaked" >&2
    exit 1
fi

echo "==> Binary tests, with no PHP on PATH"
DOTSNITCH_BINARY="$binary" "$root/vendor/bin/pest" --compact --testsuite=Binary
echo "Built $binary"
