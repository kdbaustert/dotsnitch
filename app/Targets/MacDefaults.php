<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\Shell;
use App\Contracts\SyncTarget;
use App\Data\DefaultsSnapshot;
use App\Data\Package;
use App\Support\JsonFile;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use App\Support\SideBySide;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\Step;
use LogicException;
use RuntimeException;

/**
 * macOS settings, from the `defaults write` lines of a setup script. Each line is compared with
 * what `defaults read` gives for the same domain and key. The fix runs the line, or changes its
 * value to what this Mac has.
 *
 * A setting no line writes is only reported when it's in SYSTEM_DOMAINS and changed since the
 * baseline, since macOS keeps hundreds the script never meant to manage. capture() finds the same
 * kind of change from two snapshots taken around it, or lists every one with an empty first one.
 */
final class MacDefaults implements SyncTarget
{
    /** The value types that read back as one line. Arrays and dicts don't, so those lines are skipped. */
    private const TYPES = ['-bool', '-boolean', '-int', '-integer', '-float', '-string'];

    /** One shell word: double-quoted, single-quoted or bare. */
    private const WORD = '/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|([^\s"\']+)/';

    /** The type flag for each type `defaults read-type` names that reads back as one line. */
    private const FLAGS = ['boolean' => '-bool', 'integer' => '-int', 'float' => '-float', 'string' => '-string'];

    /**
     * The domains scan() and capture() look at: the ones System Settings, the Dock, Finder and the menu bar
     * save to. Other apps and Apple's background services write elsewhere, and often.
     */
    private const SYSTEM_DOMAINS = [
        'NSGlobalDomain',
        'com.apple.AppleMultitouchMouse',
        'com.apple.AppleMultitouchTrackpad',
        'com.apple.HIToolbox',
        'com.apple.Siri',
        'com.apple.Spotlight',
        'com.apple.TextInputMenu',
        'com.apple.TimeMachine',
        'com.apple.WindowManager',
        'com.apple.controlcenter',
        'com.apple.desktopservices',
        'com.apple.dock',
        'com.apple.driver.AppleBluetoothMultitouch.mouse',
        'com.apple.driver.AppleBluetoothMultitouch.trackpad',
        'com.apple.driver.AppleHIDMouse',
        'com.apple.finder',
        'com.apple.keyboard.preferences',
        'com.apple.loginwindow',
        'com.apple.menuextra.clock',
        'com.apple.screencapture',
        'com.apple.screensaver',
        'com.apple.spaces',
        'com.apple.symbolichotkeys',
        'com.apple.systemuiserver',
        'com.apple.universalaccess',
    ];

    /**
     * Keys in SYSTEM_DOMAINS that macOS updates by itself: counters, timestamps, and what was
     * last open. They're the ones seen on one Mac, so another macOS may add more, and Ignore is
     * still the answer for those.
     */
    private const BOOKKEEPING = [
        'NSGlobalDomain' => ['AKLastIDMSEnvironment', 'AKLastLocale', 'NSLinguisticDataAssetsRequestLastInterval', 'NSLinguisticDataAssetsRequestTime'],
        'com.apple.Spotlight' => ['newFTECount', 'startTime'],
        'com.apple.WindowManager' => ['LastHeartbeatDateString.daily'],
        'com.apple.controlcenter' => ['DarkModeControlLastReloadedTheme', 'LastHeartbeatDateString.daily', 'LastPeriodicAnalyticsPostDate'],
        'com.apple.dock' => ['lastShowIndicatorTime', 'mod-count'],
        'com.apple.finder' => ['LastTrashState', 'PreferencesWindow.LastSelection'],
        'com.apple.screencapture' => ['last-analytics-stamp', 'visual-intelligence-hint-count'],
        'com.apple.systemuiserver' => ['last-analytics-stamp'],
    ];

    /** `defaults read` runs scan() starts at once. Each holds open pipes, and launchd allows a process 256 files. */
    private const READS_AT_ONCE = 16;

    /** How many settings of a type one line can't write are named. Listing every setting finds dozens. */
    private const NAMED = 5;

    /** @var array<string, array{domain: string, key: string, type: ?string, value: string, command: list<string>, script: string, path: string, line: int, text: string}> the line for each item of the last scan, by subject key */
    private array $writes = [];

    /** @var array<string, string> what this Mac had for each item of the last scan, by subject key; no entry when it had none */
    private array $values = [];

    /** @var array<string, list<string>> the words after `defaults write` for each untracked item of the last scan or capture */
    private array $additions = [];

    /**
     * @param  array<string, string>  $inherited  the environment dotsnitch was started with, for $HOME
     * @param  string  $baselineFile  where the first scan saves SYSTEM_DOMAINS, to compare later scans with
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly PathResolver $paths,
        private readonly ManifestEditor $editor,
        private readonly array $inherited,
        private readonly string $baselineFile,
    ) {}

    /**
     * The command's name, and what the script lines start with.
     */
    public function key(): string
    {
        return 'defaults';
    }

    public function label(): string
    {
        return 'macOS defaults';
    }

    public function isAvailable(): bool
    {
        return $this->paths->find('defaults') !== null;
    }

    /**
     * The names such a script usually has. `.macos` is the one from mathiasbynens/dotfiles.
     */
    public function manifestPatterns(): array
    {
        return ['.macos', 'macos.sh', 'defaults.sh'];
    }

    /**
     * Reads every setting a line writes with its own `defaults read`, about 10 ms each and
     * READS_AT_ONCE at a time. All of SYSTEM_DOMAINS comes from one more, about half a second,
     * read meanwhile. A setting there that no line writes and that changed since the baseline is
     * untracked. The first scan saves the baseline, so it finds none of those.
     *
     * @throws RuntimeException when defaults can't read every setting, or the baseline can't be saved
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        $this->forget();
        $writes = $this->writesInEach($manifests, $log);
        [$values, $now] = SideBySide::run([fn () => $this->currentValues($writes), $this->snapshot(...)]);
        $items = [];

        foreach ($writes as $setting => $write) {
            if ($values[$setting] !== null && $this->matches($write, $values[$setting])) {
                continue;
            }

            $items[] = $this->differs($write, $values[$setting]);
        }

        $baseline = $this->savedBaseline();

        if ($baseline === null) {
            JsonFile::write($this->baselineFile, $now->settings);

            return new DriftReport($this->key(), $items);
        }

        $unwritten = array_filter($now->changedSince($baseline), fn (array $setting) => ! isset($writes[implode(':', $setting)]));

        return new DriftReport($this->key(), [...$items, ...$this->changedItems(array_values($unwritten), $writes, $log)]);
    }

    /**
     * Every setting in SYSTEM_DOMAINS but BOOKKEEPING, from one `defaults read`. It takes about
     * half a second.
     *
     * @throws RuntimeException when defaults fails
     */
    public function snapshot(): DefaultsSnapshot
    {
        $result = $this->shell->run([$this->defaults(), 'read']);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) ?: "defaults read exited with code {$result->exitCode}");
        }

        return DefaultsSnapshot::parse($result->output)->only(self::SYSTEM_DOMAINS)->without(self::BOOKKEEPING);
    }

    /**
     * The settings that changed between two snapshots and that one line can write. One a line
     * already writes is reported as differing when its value isn't the line's, and one no line
     * writes as untracked. Each takes two reads, about 20 ms.
     *
     * @param  DefaultsSnapshot  $before  empty, to list every setting $after has
     * @param  list<string>  $manifests  absolute paths
     * @param  callable(string): void  $log
     */
    public function capture(DefaultsSnapshot $before, DefaultsSnapshot $after, array $manifests, callable $log): DriftReport
    {
        $this->forget();
        $writes = $this->writesInEach($manifests, $log);

        return new DriftReport($this->key(), $this->changedItems($after->changedSince($before), $writes, $log));
    }

    /**
     * Changing the line is only offered for a setting this Mac has a value for. A setting no
     * line writes can be added, or ignored when it's one macOS changes by itself.
     */
    public function actionsFor(DriftItem $item): array
    {
        $setting = $item->subject->key();

        if ($item->kind === DriftKind::Untracked) {
            return [
                Action::AddToManifest->value => Action::AddToManifest->label(),
                Action::Ignore->value => Action::Ignore->label(),
                Action::Skip->value => Action::Skip->label(),
            ];
        }

        $actions = [Action::Write->value => Action::Write->label()];

        if (isset($this->values[$setting], $this->writes[$setting])) {
            $actions[Action::UpdateManifest->value] = "Change {$this->writes[$setting]['script']} to match";
        }

        return $actions + [
            Action::Ignore->value => Action::Ignore->label(),
            Action::Skip->value => Action::Skip->label(),
        ];
    }

    /**
     * A setting that had no value can be undone by deleting it. One that had another value can't,
     * because `defaults read` doesn't say which type the old value was.
     */
    public function steps(Decision $decision): array
    {
        return match ($decision->action) {
            Action::Write => [$this->writeStep($decision->item)],
            Action::UpdateManifest => [$this->updateStep($decision->item)],
            Action::AddToManifest => [$this->addStep($decision->manifests, $decision->item)],
            Action::Ignore, Action::Skip => [],
            default => throw new LogicException("macOS defaults can't {$decision->action->value}"),
        };
    }

    private function forget(): void
    {
        $this->writes = [];
        $this->values = [];
        $this->additions = [];
    }

    /**
     * @return ?DefaultsSnapshot null before the first scan, or when the file is broken, so the scan saves a new one
     */
    private function savedBaseline(): ?DefaultsSnapshot
    {
        try {
            $data = JsonFile::read($this->baselineFile);

            return $data === null ? null : DefaultsSnapshot::fromArray($data);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Reads the type and value of each setting. One that's gone by then was deleted after the
     * snapshot, and is passed over.
     *
     * @param  list<array{string, string}>  $changed  the domain and key of each
     * @param  array<string, array{domain: string, key: string, type: ?string, value: string, command: list<string>, script: string, path: string, line: int, text: string}>  $writes
     * @param  callable(string): void  $log
     * @return list<DriftItem>
     */
    private function changedItems(array $changed, array $writes, callable $log): array
    {
        $items = [];
        $unwritable = [];

        foreach ($changed as [$domain, $key]) {
            $setting = new Package($key, $domain);
            $type = $this->shell->run([$this->defaults(), 'read-type', $domain, $key]);

            if (! $type->successful()) {
                continue;
            }

            $name = preg_match('/^Type is (\w+)/', $type->output, $match) ? $match[1] : trim($type->output);
            $flag = self::FLAGS[$name] ?? null;

            if ($flag === null) {
                $unwritable[] = "{$setting->key()} ({$name})";

                continue;
            }

            $current = $this->shell->run([$this->defaults(), 'read', $domain, $key]);

            if (! $current->successful()) {
                continue;
            }

            $value = rtrim($current->output, "\n");
            $write = $writes[$setting->key()] ?? null;

            if ($write === null) {
                $this->additions[$setting->key()] = [$domain, $key, $flag, $this->valueFor($flag, $value)];
                $items[] = new DriftItem($this->key(), DriftKind::Untracked, $setting, implode(' ', array_slice($this->additions[$setting->key()], 2)));
            } elseif (! $this->matches($write, $value)) {
                $items[] = $this->differs($write, $value);
            }
        }

        if ($unwritable !== []) {
            $count = count($unwritable);
            $log("{$count} ".($count === 1 ? 'setting has' : 'settings have')." a type one line can't write"
                .($count <= self::NAMED ? ': '.implode(', ', $unwritable) : ''));
        }

        return $items;
    }

    /**
     * @param  array<string, array{domain: string, key: string}>  $writes
     * @return array<string, ?string> what `defaults read` printed for each, keyed like $writes; null when it isn't set
     */
    private function currentValues(array $writes): array
    {
        $values = [];

        foreach (array_chunk($writes, self::READS_AT_ONCE, preserve_keys: true) as $chunk) {
            $values += SideBySide::run(array_map(fn (array $write) => function () use ($write) {
                $current = $this->shell->run([$this->defaults(), 'read', $write['domain'], $write['key']]);

                return $current->successful() ? rtrim($current->output, "\n") : null;
            }, $chunk));
        }

        return $values;
    }

    /**
     * @param  array{domain: string, key: string, type: ?string, value: string, command: list<string>, script: string, path: string, line: int, text: string}  $write
     * @param  ?string  $current  null when the setting isn't set on this Mac
     */
    private function differs(array $write, ?string $current): DriftItem
    {
        $setting = new Package($write['key'], $write['domain']);
        $this->writes[$setting->key()] = $write;

        if ($current !== null) {
            $this->values[$setting->key()] = $current;
        }

        return new DriftItem($this->key(), DriftKind::Differs, $setting, $current === null
            ? "isn't set, {$write['script']} writes {$write['value']}"
            : "is {$current}, {$write['script']} writes {$write['value']}");
    }

    private function writeStep(DriftItem $item): Step
    {
        $setting = $item->subject->key();
        $command = ($this->writes[$setting] ?? throw new RuntimeException("{$setting} wasn't in the last scan"))['command'];

        return new Step(Step::commandLine($command), function () use ($command, $item, $setting) {
            $result = $this->shell->run($command);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput) ?: "Exited with code {$result->exitCode}");
            }

            return isset($this->values[$setting])
                ? ['kind' => 'command', 'command' => $command]
                : ['kind' => 'command', 'command' => $command, 'undo' => [[$this->defaults(), 'delete', $item->subject->type, $item->subject->name]], 'environment' => []];
        });
    }

    /**
     * Swaps the value in the line for this Mac's and leaves the rest of it, such as its indent,
     * type and comment, as it was. A line added above it since the scan, such as by an add in the
     * same run, moves it down, so it's looked for from where the scan found it on.
     *
     * @throws RuntimeException when the setting wasn't in the last scan, or its value can't be one shell word
     */
    private function updateStep(DriftItem $item): Step
    {
        $setting = $item->subject->key();
        $write = $this->writes[$setting] ?? throw new RuntimeException("{$setting} wasn't in the last scan");
        $value = $this->values[$setting] ?? throw new RuntimeException("{$setting} isn't set on this Mac");
        $word = $this->shellWord($this->valueFor($write['type'], $value));

        return new Step("Change {$setting} to {$word} in {$write['script']}", fn () => $this->editor->edit(
            $write['path'],
            function (string $contents) use ($write, $word) {
                $lines = explode("\n", $contents);
                $at = array_search($write['text'], array_slice($lines, $write['line'], preserve_keys: true), true);

                if ($at === false) {
                    throw new RuntimeException("{$write['script']} changed since the scan");
                }

                $lines[$at] = $this->withValue($write['text'], $word);

                return implode("\n", $lines);
            },
        ));
    }

    /**
     * @param  list<string>  $manifests  the destination only
     *
     * @throws RuntimeException when no destination was chosen, or the value can't be one shell word
     */
    private function addStep(array $manifests, DriftItem $item): Step
    {
        $setting = $item->subject->key();
        $words = $this->additions[$setting] ?? throw new RuntimeException("{$setting} wasn't in the last scan or capture");
        $manifest = $manifests[0] ?? throw new RuntimeException("No script to add {$setting} to");
        $line = 'defaults write '.implode(' ', array_map($this->shellWord(...), $words));

        return new Step("Add {$setting} to ".basename($manifest), fn () => $this->editor->edit(
            $manifest,
            fn (string $contents) => $this->withLine($contents, $words[0], $line),
        ));
    }

    /**
     * Every file is read before anything is compared, so a later file's line for a key replaces
     * an earlier file's, the way it would when the scripts run in that order.
     *
     * @param  list<string>  $manifests
     * @param  callable(string): void  $log
     * @return array<string, array{domain: string, key: string, type: ?string, value: string, command: list<string>, script: string, path: string, line: int, text: string}>
     */
    private function writesInEach(array $manifests, callable $log): array
    {
        $writes = [];

        foreach ($manifests as $manifest) {
            $writes = [...$writes, ...$this->writesIn($manifest, $log)];
        }

        return $writes;
    }

    /**
     * The lines it can compare: `defaults write domain key value`, with or without a type from
     * TYPES. A later line for the same domain and key wins, as it would when the script runs.
     * `sudo defaults write` lines are passed over, and so is -currentHost or -app. So is an
     * untyped value that starts like a plist, which defaults would store as an array or a dict.
     *
     * @param  callable(string): void  $log
     * @return array<string, array{domain: string, key: string, type: ?string, value: string, command: list<string>, script: string, path: string, line: int, text: string}>
     */
    private function writesIn(string $manifest, callable $log): array
    {
        $writes = [];
        $skipped = 0;

        foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $index => $line) {
            if (! preg_match('/^\s*defaults\s+write\s+(.+)$/', $line, $match)) {
                continue;
            }

            $words = $this->writeWords($match[1]);
            $typed = count($words) === 4 && in_array($words[2], self::TYPES, true);
            $plain = count($words) === 3 && $this->isPlain($words[2]);

            if (! $typed && ! $plain || str_starts_with($words[0], '-')) {
                $skipped++;

                continue;
            }

            [$domain, $key] = $words;
            $writes["{$domain}:{$key}"] = [
                'domain' => $domain,
                'key' => $key,
                'type' => $typed ? $words[2] : null,
                'value' => end($words),
                'command' => [$this->defaults(), 'write', ...$words],
                'script' => basename($manifest),
                'path' => $manifest,
                'line' => $index,
                'text' => $line,
            ];
        }

        if ($skipped > 0) {
            $log("{$skipped} defaults write ".($skipped === 1 ? 'line' : 'lines').' in '.basename($manifest)." can't be compared");
        }

        return $writes;
    }

    /**
     * words(), with -g and -globalDomain spelled NSGlobalDomain the way `defaults read` prints it.
     *
     * @return list<string>
     */
    private function writeWords(string $rest): array
    {
        $words = $this->words($rest);

        if (in_array($words[0] ?? '', ['-g', '-globalDomain'], true)) {
            $words[0] = 'NSGlobalDomain';
        }

        return $words;
    }

    /**
     * Splits the rest of the line the way the shell would, as far as that goes without running
     * it: quotes come off, $HOME and an unquoted leading ~ are filled in, and a trailing comment
     * is dropped.
     *
     * @return list<string> empty for a line with any other $, a backtick, ~name for another
     *                      user's home, or a second command on it
     */
    private function words(string $rest): array
    {
        $home = $this->inherited['HOME'] ?? '';
        $words = [];

        preg_match_all(self::WORD, $rest, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as [, $double, $single, $bare]) {
            if ($single !== null) {
                $words[] = $single;

                continue;
            }

            if ($bare !== null && str_starts_with($bare, '#')) {
                break;
            }

            if ($bare === '~' || str_starts_with($bare ?? '', '~/')) {
                $bare = $home.substr($bare, 1);
            }

            $word = str_replace(['${HOME}', '$HOME'], $home, $double ?? $bare);

            if (strpbrk($word, '$`') !== false || ($bare !== null && (strpbrk($bare, ';&|') !== false || str_starts_with($bare, '~')))) {
                return [];
            }

            // Inside double quotes only \\ and \" are left to undo, since a line with $ or a backtick
            // was turned away above. Any other backslash stays, as it does in the shell.
            $words[] = $double === null ? $word : strtr($word, ['\\\\' => '\\', '\\"' => '"']);
        }

        return $words;
    }

    /**
     * The line with the last word before any comment, which is the value, swapped for $word.
     */
    private function withValue(string $line, string $word): string
    {
        preg_match('/^\s*defaults\s+write\s+/', $line, $prefix);
        preg_match_all(self::WORD, $line, $matches, PREG_OFFSET_CAPTURE, strlen($prefix[0]));
        $value = $matches[0][0];

        foreach ($matches[0] as $match) {
            if (str_starts_with($match[0], '#')) {
                break;
            }

            $value = $match;
        }

        return substr_replace($line, $word, $value[1], strlen($value[0]));
    }

    /**
     * Puts the line after the script's last `defaults write` for the same domain, or else after
     * its last one for any domain, indented like that line. With neither it goes at the end, and
     * an empty script gets a shebang first.
     */
    private function withLine(string $contents, string $domain, string $line): string
    {
        if (trim($contents) === '') {
            return "#!/usr/bin/env bash\n\n{$line}\n";
        }

        $lines = explode("\n", $contents);
        $last = null;
        $lastInDomain = null;

        foreach ($lines as $index => $existing) {
            // One that goes on to the next line can't have a line put straight after it.
            if (! preg_match('/^\s*defaults\s+write\s+(.+)$/', $existing, $match) || str_ends_with(rtrim($existing), '\\')) {
                continue;
            }

            $last = $index;

            if (($this->writeWords($match[1])[0] ?? null) === $domain) {
                $lastInDomain = $index;
            }
        }

        $anchor = $lastInDomain ?? $last;

        if ($anchor === null) {
            return $contents.(str_ends_with($contents, "\n") ? '' : "\n")."{$line}\n";
        }

        preg_match('/^\s*/', $lines[$anchor], $indent);
        array_splice($lines, $anchor + 1, 0, [$indent[0].$line]);

        return implode("\n", $lines);
    }

    /**
     * $value as one shell word that words() reads back the same: bare when nothing in it needs
     * quoting, else in single quotes, or in double quotes when it has a single quote.
     *
     * @throws RuntimeException for a line break, or a single quote with a $ or a backtick
     */
    private function shellWord(string $value): string
    {
        if (preg_match('/^[\w.\/:@=+,-]+$/', $value)) {
            return $value;
        }

        if (str_contains($value, "\n")) {
            throw new RuntimeException("{$value} has a line break, which one line can't write");
        }

        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (strpbrk($value, '$`') !== false) {
            throw new RuntimeException("{$value} has a single quote and a \$ or backtick, so the script can't quote it");
        }

        return '"'.addcslashes($value, '\\"').'"';
    }

    /**
     * What a line writes for a value `defaults read` printed. A boolean prints as 1 or 0 and is
     * written as true or false.
     *
     * @param  ?string  $type  the line's type flag, null for none
     *
     * @throws RuntimeException when a line with no type would take the value for a flag or a plist
     */
    private function valueFor(?string $type, string $current): string
    {
        if (in_array($type, ['-bool', '-boolean'], true)) {
            return $current === '1' ? 'true' : 'false';
        }

        if ($type === null && ! $this->isPlain($current)) {
            throw new RuntimeException("{$current} needs a type flag in the line to be written as it is");
        }

        return $current;
    }

    /**
     * Whether `defaults write` with no type stores $value as the string it is, rather than
     * reading it as a flag or as an array or dict.
     */
    private function isPlain(string $value): bool
    {
        return (is_numeric($value) || ! str_starts_with($value, '-')) && ! preg_match('/^\s*[({<]/', $value);
    }

    /**
     * @param  array{type: ?string, value: string}  $write
     * @param  string  $current  what `defaults read` printed, which is 1 or 0 for a boolean
     */
    private function matches(array $write, string $current): bool
    {
        return match ($write['type']) {
            '-bool', '-boolean' => $current === (in_array(strtolower($write['value']), ['true', 'yes', '1'], true) ? '1' : '0'),
            '-float' => is_numeric($current) && (float) $current === (float) $write['value'],
            default => $current === $write['value'],
        };
    }

    private function defaults(): string
    {
        return $this->paths->find('defaults') ?? 'defaults';
    }
}
