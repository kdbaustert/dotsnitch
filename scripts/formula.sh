#!/usr/bin/env bash
# Prints the Homebrew formula for a release: scripts/formula.sh v1.2.0 dist/checksums.txt
# The release workflow writes it into the tap.
set -euo pipefail

tag=$1
checksums=$2
repo=${GITHUB_REPOSITORY:-kdbaustert/dotsnitch}

# The SHA-256 checksums.txt lists for one asset.
sha_of() {
    awk -v asset="$1" '$2 == asset { print $1 }' "$checksums"
}

arm64=$(sha_of dotsnitch-arm64)
x86_64=$(sha_of dotsnitch-x86_64)

if [[ -z "$arm64" || -z "$x86_64" ]]; then
    echo "$checksums needs a line for dotsnitch-arm64 and one for dotsnitch-x86_64" >&2
    exit 1
fi

cat <<RUBY
class Dotsnitch < Formula
  desc "Compare your dotfiles with this Mac and resolve what is out of sync"
  homepage "https://github.com/$repo"
  version "${tag#v}"
  license "MIT"

  depends_on :macos

  on_arm do
    url "https://github.com/$repo/releases/download/$tag/dotsnitch-arm64"
    sha256 "$arm64"
  end

  on_intel do
    url "https://github.com/$repo/releases/download/$tag/dotsnitch-x86_64"
    sha256 "$x86_64"
  end

  def install
    bin.install Dir["dotsnitch-*"].first => "dotsnitch"
  end

  test do
    assert_match version.to_s, shell_output("#{bin}/dotsnitch --version")
  end
end
RUBY
