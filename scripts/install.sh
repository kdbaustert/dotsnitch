#!/bin/sh
# Installs dotsnitch with nothing but what ships with macOS:
#   curl -fsSL https://github.com/kdbaustert/dotsnitch/releases/latest/download/install.sh | sh
# Installs to ~/.local/bin unless you pass --prefix=DIR. Running it again upgrades in place.
set -eu

releases=${DOTSNITCH_RELEASES:-https://github.com/kdbaustert/dotsnitch/releases/latest/download}
prefix="$HOME/.local/bin"

for arg in "$@"; do
    case "$arg" in
        --prefix=*) prefix=${arg#--prefix=} ;;
        *) echo "Unknown option $arg. The only one is --prefix=DIR." >&2 && exit 1 ;;
    esac
done

if [ "$(uname -s)" != Darwin ]; then
    echo "dotsnitch only runs on macOS." >&2
    exit 1
fi

# A shell under Rosetta says x86_64, but the Mac is Apple Silicon and dotsnitch has to see that.
# sysctl lives in /usr/sbin, which a trimmed PATH can leave out, and then this would pick Intel.
if [ "$(/usr/sbin/sysctl -in hw.optional.arm64 2>/dev/null)" = 1 ]; then
    asset=dotsnitch-arm64
else
    asset=dotsnitch-x86_64
fi

download=$(mktemp -d)
trap 'rm -rf "$download"' EXIT

echo "Downloading $asset"
curl -fsSL -o "$download/$asset" "$releases/$asset"
curl -fsSL -o "$download/checksums.txt" "$releases/checksums.txt"

if ! (cd "$download" && grep " $asset\$" checksums.txt | shasum -a 256 -c --quiet >/dev/null 2>&1); then
    echo "$asset doesn't match checksums.txt, so nothing was installed." >&2
    exit 1
fi

mkdir -p "$prefix"
chmod 755 "$download/$asset"
# Copy next to the old one first, so the swap is a rename and a running dotsnitch keeps working.
cp "$download/$asset" "$prefix/.dotsnitch.new"
mv -f "$prefix/.dotsnitch.new" "$prefix/dotsnitch"

echo "Installed $("$prefix/dotsnitch" --version) at $prefix/dotsnitch"

case ":$PATH:" in
    *":$prefix:"*) ;;
    *)
        echo "$prefix isn't on your PATH. Add this line to ~/.zprofile and open a new terminal:"
        echo "    export PATH=\"$prefix:\$PATH\""
        ;;
esac
