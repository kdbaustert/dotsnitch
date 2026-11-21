# dotsnitch

dotsnitch is a command-line tool that tells you where your Mac and your dotfiles disagree. It compares two things:

- **Packages.** The package lists in your dotfiles (a `Brewfile`, a list of global Composer packages, and so on) against what's actually installed.
- **Config files.** Whether the config files in your dotfiles are symlinked into place.

For each difference it asks what you want to do. It never installs, removes or links anything you didn't choose.

The only thing it needs to know is where your dotfiles live. It follows the symlinks in your home folder back to the git repo they point into, or you pass `--repo=`. Inside that directory it finds manifests by file name at any depth, so it works with however you've laid out your repo.

It's built with [Laravel Zero](https://laravel-zero.com) and runs on Apple Silicon and Intel Macs. The architecture, Homebrew prefix and tool paths are detected when it runs.

## Status

Everything is written, including the standalone build, daily use, CI and the release setup. CI and the release workflow haven't run on GitHub yet, and there's no release to install from until the first tag is pushed. Today it can:

- detect this Mac's architecture, Rosetta, hostname, Homebrew prefix, Node install and tool paths (`config --machine`)
- find your dotfiles directory and list the manifest it'll use for each check (`config`)
- compare your Brewfile with what Homebrew has installed (plain `dotsnitch`)
- compare your global Composer, npm and pnpm packages with their lists
- compare Mac App Store apps, GitHub CLI extensions, pipx apps, uv tools and Cargo crates with theirs
- check that the config files in your dotfiles are symlinked into place
- fix what it found, one choice at a time or a whole group at once
- ignore things on one Mac without touching your dotfiles (`config --ignored` to undo)
- mark what's new since the last scan, run every day and notify you (`status`, `schedule`)
- show the count in your shell prompt (`status --quiet`)

Plain `dotsnitch` asks which checks to run. The first time, every check that's installed and has a manifest is ticked. After that it starts from what you picked last. Pass `--checks=brew,pnpm` to skip the question. Each check shows a live status while it scans, then you get one summary row per check and a table of every item that's missing, untracked, deprecated or disabled.

Dependencies don't count as untracked, and an alias or old name in your Brewfile matches the formula it points to. A Brewfile name Homebrew doesn't know shows as missing with a note saying so. If a check can't finish, its row says why, the others still run, and `dotsnitch` exits with 1.

For the global package managers:

- **Composer** only counts direct requires (`composer global show --direct`), so their dependencies never show as untracked. That holds when your manifest is Composer's own `composer.json` linked in from dotfiles, too.
- **npm** never reports the npm running the check, wherever it's installed.
- **pnpm** installs one package per `pnpm add -g`. pnpm 11 keeps each `add -g` call's packages together in one group, so an untracked package that shares a group says which packages it shares it with. If pnpm's global bin dir isn't on your login PATH, the scan tells you to run `pnpm setup`.
- **Mac App Store** goes by app id. An entry can be `497799835` or `497799835:Xcode`, and the name shows in the report. New entries get the name too, in double quotes when it has a space (`"1436522307:Transmit 5"`), so quote those in a plain list as well. It reads `mas list`'s text, since mas 7's `--json` leaves out some apps' ids. Uninstalling runs `sudo mas uninstall`, which asks for your password. `mas` lines in a Brewfile aren't read yet.
- **GitHub CLI extensions** are `owner/repo`, and `owner/repo@v1.2.0` installs with `--pin v1.2.0`. A local extension shows by its `gh-name` and can't be installed from here.
- **pipx** and **uv** match names the way pip does, so `Poetry_Core`, `poetry.core` and `poetry-core` are the same. Extras and specifiers like `httpie[socks]>=3` are kept on install. pipx only counts each app's main package, not what you injected into it, and uninstalls by venv name, so a `--suffix` install works.
- **Cargo** reads `cargo install --list`, crates from git included. `ripgrep@14.1.1` installs that version.

### Symlinked config files

This check works from the whole repo, so it runs even when there's no layout file. It expects a link for:

- each entry in a Dotbot config (`*.conf.yaml`), including `glob` and `prefix`. Entries with `if:` are skipped and logged.
- each file in a Stow package, a top-level folder holding a dotfile. `--target` and `--dotfiles` are read from `.stowrc`.
- each `*.symlink` file, so `zsh/zshrc.symlink` belongs at `~/.zshrc`.
- each link that already points into the repo, or at the same path in another folder such as an old checkout. It reads `~` and `~/.config` two levels down, plus any dot-folder the repo also has, as deep as the repo goes (`repo/.claude/skills` means `~/.claude/skills` is read).

When two layouts claim the same path, the first one wins (Dotbot, then Stow, then `*.symlink`) and the scan log says so. It can't ask, since prompts don't run under the scan's status.

A path shows as **not linked** (create the link), **conflict** (a real file is in the way: back it up and link, show the diff, or adopt your copy into the repo), **wrong target** (repoint the link) or **broken** (the repo file is gone: remove the link). Anything moved aside goes in this run's backup folder under `~/.cache/dotsnitch/backups`. Repointing leaves the old destination alone, and adopting doesn't offer a commit yet.

pipx, uv and Cargo were built from their source code and tested against fixtures, but not run on a Mac that has them installed yet.

A pin like `typescript@6` or `laravel/installer:^5` matches whatever version is installed, and Install keeps it. Version mismatches aren't flagged yet.

### Fixing what it found

After the report, dotsnitch asks how you want to handle the drift:

- **Quick review.** One list per group, such as untracked Homebrew formulae, with every item ticked for the suggested fix. Anything you untick gets one shared choice after.
- **Item by item.** One question per item, with the suggestion picked.
- **Report only.** Changes nothing and exits with 1, the same as running without a terminal.

The choices are: add it to your dotfiles, install it, uninstall it, replace a deprecated package with its successor, ignore it on this Mac, or skip it. Adding asks whether it goes in the main manifest (every Mac) or the one with this Mac's hostname as a suffix. See [Ignoring things on one Mac](#ignoring-things-on-one-mac) for what Ignore does.

You see the full plan before anything runs, and uninstalls get their own yes or no, which defaults to no. Pass `--dry-run` to see the plan and the exact commands without running any of them.

When it's done, it scans again and saves the new report. If it edited files in a git checkout, it shows `git diff --stat` and offers to commit them. That also defaults to no.

### Ignoring things on one Mac

Ignore saves the item in this Mac's `config.json` under `ignore.<check>`, so a work Mac and a personal Mac can share one Brewfile and each skip what it doesn't need. Your dotfiles don't change and the other Mac never sees it. An ignored package stays hidden whether it's missing or untracked, and an ignored link is hidden by its path in `~`.

The report leaves ignored items out, shows the count in the check's row ("In sync (2 ignored)") and ends with a line like "4 ignored on this Mac". To see them all:

```sh
dotsnitch config --ignored
```

In a terminal it then asks which ones to stop ignoring, and the next sync reports those again.

### Running without a terminal

With `--no-interaction`, or when nothing is attached to stdin, dotsnitch never stops to ask. For a choice it would normally ask about, it prints which default it used and saves nothing, so your first run in a terminal still asks:

- **Two manifests for one check:** it reads the first one listed and names the others.
- **Files for another Mac,** like `Brewfile.work`: it skips them.
- **Two Homebrews:** it uses `/opt/homebrew`.
- **No dotfiles directory found:** it stops and tells you to pass `--repo=` or set `DOTSNITCH_REPO`.

It never fixes anything this way. It reports and exits with 1 when it found drift.

### Daily use

`status` scans and reports like `sync`, but never asks anything, even in a terminal, and exits with 0 when it only found drift. It exits with 1 only when a check couldn't finish. It runs the checks `sync` last ran unless you pass `--checks=`.

```sh
dotsnitch status          # scan and show everything out of sync
dotsnitch status --new    # list only what the last scan didn't have
```

Every scan is saved, by `sync` too, and the next one marks what the last didn't have. The line above the table reads "9 out of sync, 2 new since Nov 20, 09:00" and new rows say "untracked (new)". An item only counts as new when the last scan ran the same check, so turning on a check doesn't flag everything in it. A check that fails keeps what the last scan found, so the count doesn't drop and its next good scan still has something to compare with.

To run it every day and get a notification when something new turns up:

```sh
dotsnitch schedule --install --at=09:00
dotsnitch schedule                       # when it runs, its last exit code and the end of its log
dotsnitch schedule --uninstall
```

This writes `~/Library/LaunchAgents/com.dotsnitch.status.plist`, which runs `status --notify` with the dotfiles directory, shell and PATH you installed it from. A notification covers what the last one hadn't seen, so drift a `sync` or a prompt's refresh found first still gets posted. If another `status` is running, the daily run waits up to 5 minutes for it. If the Mac is asleep at that time, launchd runs it when it wakes. Install again after you move or rebuild the binary, since the plist names its full path; `schedule` warns when that file is gone. A notification lists up to three names ("2 new items out of sync (jq, gh-dash)"). It uses `terminal-notifier` when it's installed, and clicking it opens the standalone binary in Terminal. Otherwise it goes through `osascript`, which can't open anything on click. Either way macOS may ask once whether to allow notifications.

### In your shell prompt

`status --quiet` (or `-q`) prints the saved count, or nothing when it's 0, there's no report yet, or anything goes wrong. It reads the saved report before the framework loads, so it takes about 30 ms from the standalone binary and 45 ms from source. When the report is older than `--max-age` (default `24h`; takes `30m`, `6h`, `2d` or seconds), it starts `status` in the background for the next prompt, at most once per `--max-age` and never while another is running. Add `--new` to count only new items.

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

The outer parentheses make Starship drop the whole group when the command prints nothing, so the symbol only shows when something is out of sync.

## What it will check

| Check | Manifest it looks for |
| --- | --- |
| Homebrew | `Brewfile`, `.Brewfile` |
| Mac App Store | `mas.txt`, `Masfile`, `mas.sh` |
| Composer (global) | `composer/composer.json`, `.composer/composer.json`, `composer-global.json`, `Composerfile` |
| npm (global) | `npm-global.txt`, `.npm-globals`, `npm/package.json` |
| pnpm (global) | `pnpm-global.txt`, `.pnpm-globals`, `pnpm.sh` |
| GitHub CLI extensions | `gh-extensions.txt`, `gh-extensions.sh` |
| pipx | `pipx.txt`, `Pipxfile`, `pipx.sh` |
| uv tools | `uv-tools.txt`, `uv-tools.sh` |
| Cargo | `cargo.txt`, `Cargofile`, `cargo.sh` |
| Symlinked config files | `*.conf.yaml`, `.stowrc`, `*.symlink` |

Each check's patterns live in its class under `app/Targets`.

A manifest can be a plain list with one package per line (`#` comments are fine), a JSON file read from its `require` (Composer) or `dependencies` (npm) section, or, for a check with a `.sh` pattern, a setup script whose bash arrays, like `pkgs=( ... )` and `pkgs+=( ... )`, hold the names. Only arrays the script expands, as in `pnpm add -g "${pkgs[@]}"` or `for app in "${apps[@]}"`, count; a script that expands none has every array read. dotsnitch adds new entries to the first array that spans more than one line, and leaves a line it can't edit safely, such as one holding two names, for you to change by hand.

A file with your Mac's hostname as a suffix, like `Brewfile.kennys-macbook-pro`, is always read on that Mac on top of the main file. Any other suffix, like `Brewfile.work`, is treated as a file for another Mac. dotsnitch asks once whether to read it here and remembers your answer.

## Installing

dotsnitch is one file with PHP built in, so the Mac doesn't need PHP or Composer. It runs on macOS on Apple Silicon and Intel.

```sh
curl -fsSL https://github.com/kdbaustert/dotsnitch/releases/latest/download/install.sh | sh
```

The script only uses what ships with macOS. It picks the Apple Silicon or Intel build (Apple Silicon even from a Rosetta shell), checks it against the release's `checksums.txt` and puts it at `~/.local/bin/dotsnitch`. To install somewhere else, end the command with `sh -s -- --prefix=/usr/local/bin` instead of `sh`. If that folder isn't on your PATH, it prints the line to add to `~/.zprofile`. Run it again to upgrade.

Once `kdbaustert/homebrew-tap` exists, Homebrew works too:

```sh
brew install kdbaustert/tap/dotsnitch
```

The binary carries the ad-hoc signature its PHP runtime was linked with, and that's enough for macOS to run it. `curl` doesn't mark downloads as quarantined. If you download it in a browser instead, clear the flag before the first run with `xattr -d com.apple.quarantine dotsnitch`.

### Uninstalling

1. `dotsnitch schedule --uninstall`, if you turned on the daily run
2. `rm ~/.local/bin/dotsnitch`, or `brew uninstall dotsnitch`
3. `rm -rf ~/.config/dotsnitch ~/.local/state/dotsnitch ~/.cache/dotsnitch` to drop its settings, reports and backups (see [Where it keeps things](#where-it-keeps-things))

## Running it

1. See what dotsnitch detected about this Mac:

   ```sh
   dotsnitch config --machine
   ```

2. List the manifests it found in your dotfiles:

   ```sh
   dotsnitch config --repo=~/dotfiles
   ```

   Leave out `--repo=` and it checks, in order: the `DOTSNITCH_REPO` environment variable, the path you saved earlier, then the git repo that most symlinks in `~` and `~/.config` point into. If none of them lead into a repo, it asks, or without a terminal, it stops and says to pass `--repo=`.

3. Compare your Brewfile with this Mac:

   ```sh
   dotsnitch --repo=~/dotfiles --checks=brew
   ```

   A scan only reads from Homebrew. Every brew call runs with `HOMEBREW_NO_AUTO_UPDATE=1`, so it never updates Homebrew, and it takes 2 to 3 seconds. Nothing is installed, removed or edited until you confirm the plan.

4. Preview the fixes without changing anything:

   ```sh
   dotsnitch --repo=~/dotfiles --checks=brew --dry-run
   ```

## Where it keeps things

| File | What's in it |
| --- | --- |
| `~/.config/dotsnitch/config.json` | Your saved answers for this Mac: the dotfiles path, the Homebrew prefix, which manifests to read, and what's ignored |
| `~/.local/state/dotsnitch/last-report.json` | The last scan's results and what was new in it (`$XDG_STATE_HOME` is used if set) |
| `~/.local/state/dotsnitch/reports/` | The last 30 scans, one file each, for looking back |
| `~/.local/state/dotsnitch/status.lock` | Keeps two `status` runs from scanning at once. Its time marks the last background refresh |
| `~/.local/state/dotsnitch/last-notified.json` | What the last `status --notify` saw, so the next one posts only what came after |
| `~/.local/state/dotsnitch/schedule.log` | What the daily run printed. Past 1 MB it moves to `schedule.log.1`, replacing the older one |
| `~/Library/LaunchAgents/com.dotsnitch.status.plist` | The daily run, from `schedule --install` |
| `~/.cache/dotsnitch/backups/<timestamp>/` | A copy of each manifest from before its first edit in that run, and any file a new symlink replaced, each at its full path (`$XDG_CACHE_HOME` is used if set) |

Delete `config.json` to have dotsnitch ask its questions again.

## Development

Working from source needs PHP 8.4 or newer and Composer (`brew install php composer`). Run `composer install`, then `./dotsnitch` from the repo. To use the source copy from anywhere, symlink it onto your PATH with `ln -s "$PWD/dotsnitch" ~/.local/bin/dotsnitch`.

```sh
composer test      # Pest
composer lint      # Pint, check only
composer format    # Pint, rewrites files
```

### Building the standalone binary

```sh
composer build
```

This writes `builds/dotsnitch-arm64` (or `-x86_64` on Intel): the PHAR joined to a static PHP runtime, so the Mac running it doesn't need PHP. The script pins static-php-cli and the PHP version, compiles in the extensions listed in `scripts/extensions.txt`, embeds the few php.ini settings it needs (a Mac's own php.ini is never read), stops if the binary links against anything outside `/usr/lib` and `/System/Library`, and then runs the Binary test suite against the new binary. Those tests run it with only `HOME` and `/usr/bin:/bin` on `PATH`, so there's no PHP to fall back on. Each one starts from a temporary `HOME` holding a copy of `tests/Fixtures/dotfiles`. They check that a first run finds the repo, reports drift and saves no settings, that a dry run of Homebrew and symlinks changes nothing, that `status --quiet` prints the count in under 100 ms, that a stale report makes the binary start itself in the background, and that every extension in `scripts/extensions.txt` made it in. That last one reads the hidden `dotsnitch about --extensions`, which lists the binary's PHP extensions one per line. Plain `dotsnitch about` prints the dotsnitch and PHP versions. To run them against a binary you already have:

```sh
DOTSNITCH_BINARY=builds/dotsnitch-arm64 vendor/bin/pest --testsuite=Binary
```

Plain `composer test` leaves that suite out.

The first build downloads static-php-cli and compiles PHP, which took about 2 minutes on an M-series Mac. Later builds reuse the runtime cached in `builds/spc` and take about 6 seconds, unless a pin or the extension list changes. The compile needs `autoconf`, `automake`, `bison`, `cmake` and `re2c` from Homebrew. If it stops on a missing tool, run `builds/spc/spc doctor` from `builds/spc` to see what's missing. Add `--auto-fix` only if you want it to run `brew install` for you.

`pint.json` uses Laravel's preset plus four stricter rules: every file declares `strict_types`, classes are `final` unless they're abstract, and comparisons and `in_array`-style calls are strict.

### CI

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests, on `macos-15` (Apple Silicon) and `macos-15-intel`. Each runs lint and the tests, builds the binary for its architecture and uploads it as `dotsnitch-arm64` or `dotsnitch-x86_64`. A last job downloads each binary on a fresh runner and runs the Binary suite against it. The static PHP runtime is cached, keyed on the pins in `scripts/build.sh` and on `scripts/extensions.txt`, so only a change to those compiles PHP again.

### Releasing

Push a tag like `v0.1.0`. `.github/workflows/release.yml` runs the whole CI workflow on it, with the tag as the binaries' version, then publishes a GitHub release holding `dotsnitch-arm64`, `dotsnitch-x86_64`, `checksums.txt` and `install.sh`. If the repo has a `HOMEBREW_TAP_TOKEN` secret that can push to `kdbaustert/homebrew-tap`, it also writes `Formula/dotsnitch.rb` there with `scripts/formula.sh`. Without the secret that step is skipped.

Tests never touch your real Mac. Commands go through `App\Contracts\Shell`, and tests swap in `Tests\Fakes\FakeShell`, which replays canned output. The exceptions run real tools on temporary folders only. `plutil` reads back the written plist, so a mistake in the hand-built XML fails a test, and `scripts/install.sh` installs from a fake release folder through a `file://` URL. The plist also has to match `tests/Fixtures/launchd/com.dotsnitch.status.plist`, so a change to it shows up in the diff.

## License

MIT
