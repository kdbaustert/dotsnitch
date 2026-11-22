# dotsnitch

> [!WARNING]
> dotsnitch is in early development. Commands, flags and the files it saves can change between releases, and some checks haven't been run on a real Mac yet. Try `--dry-run` before you let it change anything.

dotsnitch tells you where your Mac and your dotfiles have drifted apart. It checks whether the packages listed in your dotfiles (a `Brewfile`, your global Composer packages and so on) match what's installed, and whether your config files are symlinked into place.

When it finds a difference, it asks what you want to do about it. Nothing gets installed, removed or linked unless you picked it.

You don't have to tell it much. It follows the symlinks in your home folder back to the git repo they point into, or you pass `--repo=`. Then it finds manifests in that repo by file name at any depth, so it should work however your repo is laid out.

It runs on Apple Silicon and Intel Macs and works out the architecture, Homebrew prefix and tool paths each time. It's built with [Laravel Zero](https://laravel-zero.com), but a release is one file with PHP built in, so you don't need PHP installed.

There's no full release yet, only a beta. The install command below starts working with the first full release. Until then, install the beta or run it from source (see [Development](#development)):

```sh
beta=https://github.com/kdbaustert/dotsnitch/releases/download/v0.1.0-beta.2
curl -fsSL "$beta/install.sh" | DOTSNITCH_RELEASES="$beta" sh
```

## What it does

- compares your Brewfile with what Homebrew has installed
- compares your global Composer, npm and pnpm packages, Mac App Store apps, VS Code extensions, GitHub CLI extensions, pipx apps, uv tools and Cargo crates with their lists
- flags a package that's installed at a version its pin doesn't allow
- checks that the config files in your dotfiles are symlinked into place
- fixes what it found, one item at a time or a whole group at once, and undoes the file and link changes if you want them back
- installs everything that's missing on a new Mac with one command
- ignores things on one Mac without touching your dotfiles
- marks what's new since the last scan, runs every day and notifies you
- shows the count in your shell prompt, and prints a scan as JSON for scripts
- lists the packages that have a newer version

## Install

```sh
curl -fsSL https://github.com/kdbaustert/dotsnitch/releases/latest/download/install.sh | sh
```

That puts `dotsnitch` in `~/.local/bin`. The script only uses tools that come with macOS. It grabs the Apple Silicon or Intel build (Apple Silicon even from a Rosetta shell) and checks it against the release's `checksums.txt` before installing. If `~/.local/bin` isn't on your PATH, it tells you what to add to `~/.zprofile`. To upgrade later, run `dotsnitch self-update`.

To put it somewhere else, swap the last `sh` for `sh -s -- --prefix=/usr/local/bin`.

Once the `kdbaustert/homebrew-tap` repo exists, Homebrew will work too:

```sh
brew install kdbaustert/tap/dotsnitch
```

`dotsnitch --version` shows which release you have, like `Dotsnitch v0.1.0`.

### Upgrading

```sh
dotsnitch self-update
```

That downloads the latest release's build for this Mac, checks it against `checksums.txt` and swaps it in for the binary you ran. When you already have the latest, it says so and downloads nothing. A copy Homebrew installed is left to `brew upgrade dotsnitch`, and a source checkout has nothing to replace. I haven't run it against a real release yet, because there isn't a full one. It's tested against a release folder on disk.

### Tab completion

```sh
dotsnitch completion zsh > ~/.zfunc/_dotsnitch
```

Put that folder on your `fpath` before `compinit` runs. `dotsnitch completion bash` and `dotsnitch completion fish` print the scripts for those shells.

If you download the binary in a browser instead of with curl, macOS quarantines it. Clear that before the first run:

```sh
xattr -d com.apple.quarantine dotsnitch
```

curl downloads don't get that flag. The binary only has the ad-hoc signature its PHP runtime was linked with, and that's enough for macOS to run it.

## Getting started

Check what it detected about this Mac (architecture, Rosetta, hostname, Homebrew prefix, Node install and tool paths):

```sh
dotsnitch config --machine
```

See which manifest it'll use for each check:

```sh
dotsnitch config --repo=~/dotfiles
```

Without `--repo=` it looks in this order: the `DOTSNITCH_REPO` environment variable, the path you saved last time, then the git repo that most of the symlinks in `~` and `~/.config` point into. If none of those lead to a repo it asks you. With no terminal, it stops and tells you to pass `--repo=`.

Then compare your Brewfile with the Mac:

```sh
dotsnitch --repo=~/dotfiles --checks=brew
```

A scan only reads. Every brew call runs with `HOMEBREW_NO_AUTO_UPDATE=1`, so it won't update Homebrew on you, and it takes 2 to 3 seconds. Nothing changes until you confirm the plan. `--dry-run` shows you the plan and the exact commands without running any of them:

```sh
dotsnitch --repo=~/dotfiles --checks=brew --dry-run
```

Plain `dotsnitch` (the `sync` command) asks which checks to run. The first time, it ticks every check that's installed and has a manifest. After that it starts from what you picked last. `--checks=brew,pnpm` skips the question.

You get a live status for each check while it scans. Then there's a summary row per check and a list of everything not installed, untracked, deprecated or disabled, grouped by check. The checks sit two across where the terminal is wide enough for a pair. Under each package, `sync` says what your dotfiles have and what this Mac has, like "listed in Brewfile" and "not installed". If a check can't finish, its row says why, the others still run, and dotsnitch exits with 1.

## What it checks

| Check | Manifest it looks for |
| --- | --- |
| Homebrew | `Brewfile`, `.Brewfile` |
| Mac App Store | `mas.txt`, `Masfile`, `mas.sh`, or the `mas` lines of a `Brewfile` |
| VS Code extensions | `vscode-extensions.txt`, or the `vscode` lines of a `Brewfile` |
| Composer (global) | `composer/composer.json`, `.composer/composer.json`, `composer-global.json`, `Composerfile` |
| npm (global) | `npm-global.txt`, `.npm-globals`, `npm/package.json` |
| pnpm (global) | `pnpm-global.txt`, `.pnpm-globals`, `pnpm.sh` |
| GitHub CLI extensions | `gh-extensions.txt`, `gh-extensions.sh` |
| pipx | `pipx.txt`, `Pipxfile`, `pipx.sh` |
| uv tools | `uv-tools.txt`, `uv-tools.sh` |
| Cargo | `cargo.txt`, `Cargofile`, `cargo.sh` |
| Symlinked config files | `*.conf.yaml`, `.stowrc`, `*.symlink` |

Each check's file patterns live in its class under `app/Targets`.

### Manifest formats

A manifest can be:

- a plain list, one package per line or several with spaces between them, with `#` comments if you like
- a JSON file, read from `require` (Composer) or `dependencies` (npm)
- a setup script that keeps the names in bash arrays like `pkgs=( ... )` and `pkgs+=( ... )`, for checks with a `.sh` pattern

In a script, only the arrays it actually expands count, as in `pnpm add -g "${pkgs[@]}"` or `for app in "${apps[@]}"`. If it expands none, every array is read. New entries go in the first array that spans more than one line. A line it can't edit safely, like one with two names on it, is left for you to change by hand.

### One Mac or every Mac

A manifest with your hostname on the end, like `Brewfile.kennys-macbook-pro`, is read on that Mac on top of the main one. Any other suffix, like `Brewfile.work`, is taken to be for another Mac. dotsnitch asks once whether to read it here and remembers your answer.

### Notes per check

- **Homebrew:** dependencies don't count as untracked, and an alias or old name in your Brewfile matches the formula it points to. A name Homebrew doesn't know shows as not installed, with a note saying so. Its `mas` and `vscode` lines belong to the Mac App Store and VS Code checks, which read and edit them there.
- **Composer** only looks at direct requires (`composer global show --direct`), so dependencies never show up as untracked. That's true when your manifest is Composer's own `composer.json` linked in from your dotfiles, too.
- **npm** never reports the npm that's running the check, wherever it's installed.
- **pnpm** installs one package per `pnpm add -g`. pnpm 11 keeps the packages from each `add -g` call together in a group, so an untracked package in a shared group tells you which packages it shares it with. If pnpm's global bin dir isn't on your login PATH, the scan tells you to run `pnpm setup`.
- **Mac App Store** goes by app id. An entry can be `497799835` or `497799835:Xcode`, and the report shows the name. New entries get the name too, in double quotes when it has a space or an apostrophe (`"1436522307:Transmit 5"`), so quote those yourself in a plain list. A name with a `"`, `$`, backtick, backslash or `#` in it is left off, and the entry is just the id. It reads the text from `mas list`, because mas 7's `--json` leaves out some apps' ids. Uninstalling runs `sudo mas uninstall`, so expect a password prompt.
- **VS Code extensions** are `publisher.name` ids from `code --list-extensions`, matched whatever their case. `publisher.name@1.2.3` pins a version. A Brewfile only counts as this check's manifest once it has a `vscode` line, and the same goes for `mas` lines and the Mac App Store check.
- **GitHub CLI extensions** are `owner/repo`, and `owner/repo@v1.2.0` installs with `--pin v1.2.0`. A local extension shows up by its `gh-name` and can't be installed from here.
- **pipx** and **uv** match names the way pip does, so `Poetry_Core`, `poetry.core` and `poetry-core` are the same package. Extras and specifiers like `httpie[socks]>=3` are kept when installing. `ruff >= 0.6` with spaces reads the same, but dotsnitch only edits that line when the entry is in quotes. pipx only counts each app's main package, not whatever you injected into it, and uninstalls by venv name so a `--suffix` install works.
- **Cargo** reads `cargo install --list`, crates from git included. `ripgrep@14.1.1` installs that version.

A package that's installed at a version its pin doesn't allow is reported as "pinned to 6", and Install puts the pinned version in. `typescript@6` allows any 6.x, `ripgrep@14.1.1` allows only that, and `laravel/installer:^5` allows 5.0 up to 6. pipx and uv read pip's specifiers (`>=3`, `==2.*`), and a GitHub CLI extension has to be on the exact tag or commit. A pin dotsnitch can't read, like a wider range, matches whatever is installed.

I haven't run the pipx, uv and Cargo checks on a Mac that has them installed. They're written from those tools' source code and tested against fixtures.

## Symlinked config files

This check works from the whole repo, so it runs even when there's no layout file. It expects a link for:

- each entry in a Dotbot config (`*.conf.yaml`), `glob` and `prefix` included. Entries with `if:` are skipped and logged.
- each file in a Stow package, meaning a top-level folder that holds a dotfile. A submodule never counts, and a repo with a Dotbot config needs a `.stowrc` before any folder does. `--target` and `--dotfiles` are read from `.stowrc`.
- each `*.symlink` file, so `zsh/zshrc.symlink` belongs at `~/.zshrc`.
- each link that already points into the repo, or at the same path somewhere else, like an old checkout. It reads `~` and `~/.config` two levels down, plus any dot-folder the repo also has, as deep as the repo goes. So `repo/.vim/pack` means it reads `~/.vim/pack`.

If two layouts claim the same path, the first one wins (Dotbot, then Stow, then `*.symlink`) and the scan log says so. It can't ask you, because prompts don't run under the scan's status display.

Each path that's off comes back as one of these:

- **not linked:** create the link
- **conflict:** a real file is in the way. Back it up and link, show the diff, or adopt your copy into the repo.
- **wrong target:** repoint the link, or repoint it and move what it pointed at into the backup folder
- **broken:** the repo file is gone, so remove the link

Anything moved out of the way goes in this run's backup folder under `~/.cache/dotsnitch/backups`. A plain repoint leaves the old destination where it is.

## Fixing what it found

After the report it asks how you want to handle the drift:

- **Quick review:** one list per group, like untracked Homebrew formulae, with every item ticked for the suggested fix. Anything you untick gets one shared choice after.
- **Item by item:** one question per item, with the suggestion already picked.
- **Report only:** changes nothing and exits with 1, same as running without a terminal.

For each item you can add it to your dotfiles, install it, uninstall it, swap a deprecated package for its successor, ignore it on this Mac, or skip it. Adding asks whether it goes in the main manifest (every Mac) or the one with this Mac's hostname on the end. A setup script only gets that choice once the hostname copy exists, since a new empty one has no array to add to.

You always see the whole plan before anything runs. Uninstalls get their own yes or no, and it defaults to no.

When it's done it scans again and saves the new report. If it edited files in a git checkout, it shows `git diff --stat` and offers to commit them. That defaults to no as well.

### Undoing a sync

```sh
dotsnitch undo            # the last sync that changed anything
dotsnitch undo --dry-run  # list what it would put back
```

`undo` puts back the manifest edits, links and moved files from a sync, last change first, after showing you the list and asking. To undo an older one, pass the file that sync said it recorded its changes in. It can't take back a package install or uninstall, or a commit, so it lists those and leaves them.

### Setting up a new Mac

```sh
dotsnitch sync --install-missing
```

That installs every listed package that isn't installed, without asking anything, and leaves all other drift for a plain `sync`. It prints the commands before it runs them, and with `--dry-run` it stops there. It works with `--no-interaction` too, so it can go in a bootstrap script.

## Ignoring things on one Mac

Ignore saves the item in this Mac's `config.json` under `ignore.<check>`. That way a work Mac and a personal Mac can share one Brewfile and each skip what it doesn't need. Your dotfiles don't change and the other Mac never sees it. An ignored package stays hidden whether it's not installed or untracked, and an ignored link is hidden by its path in `~`.

Ignored items are left out of the report. The check's row shows how many ("In sync (2 ignored)") and the report ends with a line like "4 ignored on this Mac". To see them:

```sh
dotsnitch config --ignored
```

In a terminal it then asks which ones to stop ignoring, and the next sync reports those again.

## Running without a terminal

With `--no-interaction`, or with nothing attached to stdin, dotsnitch never stops to ask. Where it would normally ask, it picks a default, prints which one, and saves nothing, so your first run in a terminal still asks:

- two manifests for one check: it reads the first one and names the others
- files for another Mac, like `Brewfile.work`: it skips them
- two Homebrews: it uses `/opt/homebrew`
- no dotfiles directory found: it stops and tells you to pass `--repo=` or set `DOTSNITCH_REPO`

It never fixes anything this way. It reports, and exits with 1 if it found drift. The exception is `sync --install-missing`, which you asked to install things.

## Daily use

`status` scans and reports like `sync`, but never asks anything, even in a terminal. It exits with 0 when all it found was drift, and with 1 only when a check couldn't finish. It runs every check that's installed and has a manifest, whatever you last picked in `sync`. `--checks=brew,npm` narrows it.

```sh
dotsnitch status          # scan and show everything out of sync
dotsnitch status --new    # only what the last scan didn't have
```

Every scan gets saved, by `sync` too, and the next one marks what's new. The line above the list reads like "9 out of sync, 2 new since Nov 20, 09:00", or just "9 out of sync" when nothing is new, and new items say "untracked (new)". Something only counts as new if the last scan ran the same check, so turning on a check doesn't flag everything in it. A check that fails keeps what the last scan found, so the count doesn't drop and the next good scan still has something to compare with.

### In a script

```sh
dotsnitch status --json | jq '.checks.brew.items[] | select(.new) | .item'
```

`status --json` prints the scan as JSON and nothing else. Each check has its `label`, an `error` (null unless it couldn't finish), how many items are `ignored`, and its `items`, each with a `kind` such as `missing` or `untracked`, the `item` (like `formula:jq`), a `detail` and whether it's `new`. The exit code is the same as plain `status`.

### Looking back

```sh
dotsnitch history
```

One row for each of the last 30 scans, newest first: when it ran, how many items were out of sync, how many were new, and the count per check.

### What has a newer version

```sh
dotsnitch outdated
```

This asks Homebrew, npm and Composer which of your installed packages have a newer version and lists them with both versions. It leaves out a formula you've held back with `brew pin`. It only lists them, and none of it counts as out of sync, so the prompt count and the daily notification stay about your dotfiles. Nothing was outdated on my Mac when I wrote this, so all three are tested against sample output I wrote by hand, and I haven't seen a real outdated entry come through.

### Every day, with a notification

```sh
dotsnitch schedule --install --at=09:00
dotsnitch schedule                       # when it runs, its last exit code and the end of its log
dotsnitch schedule --uninstall
```

`--install` writes `~/Library/LaunchAgents/com.dotsnitch.status.plist`, which runs `status --notify` with the dotfiles directory, shell and PATH you installed it from. If the Mac's asleep at that time, launchd runs it when it wakes. If another `status` or a `sync` is already running, the daily run waits up to 5 minutes for it.

Each notification covers what the last one hadn't seen, so drift a `sync` or a prompt refresh found first still gets posted. It names up to three items ("2 new items out of sync (jq, gh-dash)"). With `terminal-notifier` installed, clicking it opens the standalone binary in Terminal. Without it, notifications go through `osascript` and clicking does nothing. Either way macOS may ask once whether to allow them.

The plist has the binary's full path in it, so run `--install` again after you move or rebuild the binary. `schedule` warns you when that file's gone.

### In your shell prompt

`status --quiet` (or `-q`) prints the saved count. It prints nothing when the count is 0, when there's no report yet, or when anything goes wrong. It reads the saved report before the framework loads, so it takes about 30 ms from the binary and 45 ms from source.

When the report is older than `--max-age` (default `24h`, also takes `30m`, `6h`, `2d` or seconds), it starts `status` in the background so the next prompt has a fresh count. It does that at most once per `--max-age`, and never while another one's running. Add `--new` to count only new items.

zsh:

```zsh
dotsnitch_prompt() { local n=$(dotsnitch status --quiet); [[ -n $n ]] && print -n "⇅$n "; }
setopt PROMPT_SUBST
RPROMPT='$(dotsnitch_prompt)'
```

Starship (`~/.config/starship.toml`):

```toml
[custom.dotsnitch]
command = "dotsnitch status --quiet"
when = true
format = "([⇅$output]($style) )"
style = "yellow"
```

The outer parentheses make Starship drop the whole group when the command prints nothing, so the symbol only shows up when something's out of sync.

## Where it keeps things

| File | What's in it |
| --- | --- |
| `~/.config/dotsnitch/config.json` | Your saved answers for this Mac: the dotfiles path, the Homebrew prefix, which manifests to read, and what's ignored |
| `~/.local/state/dotsnitch/last-report.json` | The last scan's results and what was new in it (`$XDG_STATE_HOME` is used if set) |
| `~/.local/state/dotsnitch/reports/` | The last 30 scans, one file each. `dotsnitch history` lists them |
| `~/.local/state/dotsnitch/syncs/` | The last 30 syncs that changed anything, one file each: every step it ran, what that step changed, and the error from any step that failed. `dotsnitch undo` reads these. It's saved again after each step, so a sync that dies partway still leaves one |
| `~/.local/state/dotsnitch/status.lock` | Keeps two `status` runs, or a `status` and a `sync`, from scanning at once. Its time marks the last background refresh |
| `~/.local/state/dotsnitch/last-notified.json` | What the last `status --notify` saw, so the next one posts only what came after |
| `~/.local/state/dotsnitch/schedule.log` | What the daily run printed. Past 1 MB it moves to `schedule.log.1`, replacing the older one |
| `~/Library/LaunchAgents/com.dotsnitch.status.plist` | The daily run, from `schedule --install` |
| `~/.cache/dotsnitch/backups/<timestamp>/` | A copy of each manifest from before its first edit in that run, and any file a new symlink replaced, each at its full path (`$XDG_CACHE_HOME` is used if set) |

Delete `config.json` and it'll ask its questions again.

## Uninstalling

1. `dotsnitch schedule --uninstall`, if you set up the daily run
2. `rm ~/.local/bin/dotsnitch`, or `brew uninstall dotsnitch`
3. `rm -rf ~/.config/dotsnitch ~/.local/state/dotsnitch ~/.cache/dotsnitch` to clear out its settings, reports and backups

## Development

You'll need PHP 8.4 or newer and Composer (`brew install php composer`). Run `composer install`, then `./dotsnitch` from the repo. To use your source copy from anywhere, link it onto your PATH:

```sh
ln -s "$PWD/dotsnitch" ~/.local/bin/dotsnitch
```

```sh
composer test      # Pest
composer lint      # Pint, check only
composer format    # Pint, rewrites files
```

`pint.json` is Laravel's preset plus four stricter rules: every file declares `strict_types`, classes are `final` unless they're abstract, and comparisons and `in_array`-style calls are strict.

### Tests

Tests never touch your real Mac. Commands go through `App\Contracts\Shell`, and tests swap in `Tests\Fakes\FakeShell`, which replays canned output. The few exceptions run real tools on temp folders. `plutil` reads back the written plist, so a mistake in the hand-built XML fails a test, and the plist also has to match `tests/Fixtures/launchd/com.dotsnitch.status.plist`, so any change to it shows up in the diff. `scripts/install.sh` runs against a fake release folder, with `DOTSNITCH_RELEASES=file://...` pointing it there instead of at GitHub.

Every manifest format gets a round-trip test as well. Read the file, add a package, remove it again, and the file has to come back exactly as it was.

### Building the binary

```sh
composer build
```

That writes `builds/dotsnitch-arm64` (or `-x86_64` on Intel), which is the PHAR joined to a static PHP runtime. The build script pins static-php-cli and the PHP version and compiles in the extensions listed in `scripts/extensions.txt`. It also bakes in the few php.ini settings dotsnitch needs, since the binary never reads the Mac's own php.ini. If the result links against anything outside `/usr/lib` and `/System/Library`, the build fails.

The first build downloads static-php-cli and compiles PHP, which took about 2 minutes on an M-series Mac. After that the runtime is cached in `builds/spc` and a build takes about 6 seconds, unless you change a pin or the extension list. Compiling needs `autoconf`, `automake`, `bison`, `cmake` and `re2c` from Homebrew. If it stops on a missing tool, run `builds/spc/spc doctor` from `builds/spc` to see what's missing. Only add `--auto-fix` if you want it to run `brew install` for you.

The version baked in is `$DOTSNITCH_VERSION` if it's set (a release sets it to the tag), otherwise `git describe --tags`, otherwise `unreleased`. The build doesn't re-sign the binary. `codesign` rejects the joined file, and the ad-hoc signature the PHP runtime was linked with is enough for macOS to run it.

Last of all, the build runs the Binary test suite against what it just made. Those tests run the binary with only `HOME` and `/usr/bin:/bin` on `PATH`, so there's no PHP to fall back on, and each one starts from a temp `HOME` holding a copy of `tests/Fixtures/dotfiles`. They check that:

- it runs with no PHP on PATH and reads this Mac's details with `sysctl` and `scutil`
- a first run finds the repo, reports drift and saves no settings
- a dry run of Homebrew and symlinks changes nothing
- `status --quiet` prints the count in under 100 ms (300 ms on GitHub's runners, which are slower)
- a stale report makes the binary start itself in the background
- every extension in `scripts/extensions.txt` made it in

That last one uses the hidden `dotsnitch about --extensions`, which lists the binary's PHP extensions one per line. Plain `dotsnitch about` prints the dotsnitch and PHP versions.

`composer test` skips that suite. To run it against a binary you already have:

```sh
DOTSNITCH_BINARY=builds/dotsnitch-arm64 vendor/bin/pest --testsuite=Binary
```

### CI

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests, on `macos-15` (Apple Silicon) and `macos-15-intel`. Each one runs lint and the tests, builds the binary for its architecture and uploads it as `dotsnitch-arm64` or `dotsnitch-x86_64`. A last job downloads each binary on a fresh runner and runs the Binary suite against it there. The static PHP runtime is cached, keyed on the pins in `scripts/build.sh` and on `scripts/extensions.txt`, so PHP only gets compiled again when one of those changes.

`macos-15-intel` is the last Intel image GitHub offers. When it's retired in 2027, CI loses its Intel builds.

### Releasing

Push a tag like `v0.1.0`. `.github/workflows/release.yml` runs the whole CI workflow on it, with the tag as the version, then publishes a GitHub release with `dotsnitch-arm64`, `dotsnitch-x86_64`, `checksums.txt` and `install.sh`.

A tag with a hyphen in it, like `v0.1.0-beta.1`, is published as a prerelease. GitHub doesn't count it as the latest release, so the install command keeps fetching the last full one.

If the repo has a `HOMEBREW_TAP_TOKEN` secret that can push to `kdbaustert/homebrew-tap`, a full release also writes `Formula/dotsnitch.rb` there with `scripts/formula.sh`. Without the secret, or for a prerelease, it skips that step. To see the formula for a release without pushing anything, pass it the tag and that release's `checksums.txt`:

```sh
scripts/formula.sh v0.1.0 checksums.txt
```

## Contributing

Bug reports and pull requests are welcome. Since dotsnitch is still early and things move around, open an issue before you start on anything big, so you don't build on something that's about to change.

When you report a bug, include your macOS version, whether the Mac is Apple Silicon or Intel, the output of `dotsnitch about`, and what the command printed. If it's about a manifest, a few lines of the file help a lot.

For a pull request:

1. Branch off `main` and send the PR back to it.
2. Run `composer lint` and `composer test` before you push. CI runs both, plus a build on each architecture.
3. Add a test for any behavior you change. Tests mustn't touch the real Mac, so run commands through `FakeShell` (see [Tests](#tests)).
4. A new manifest format needs a round-trip test like the others.
5. If the change is something a user would notice, update this README in the same PR.

## License

dotsnitch is released under the [MIT License](LICENSE).
