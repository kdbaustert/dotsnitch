<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\Shell;
use App\Contracts\SyncTarget;
use App\Data\Package;
use App\Support\PathResolver;
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
 * what `defaults read` gives for the same domain and key, and the fix runs the line.
 *
 * It only goes one way. A setting changed on this Mac that no line writes isn't reported, since
 * every app keeps hundreds the script never meant to manage.
 */
final class MacDefaults implements SyncTarget
{
    /** The value types that read back as one line. Arrays and dicts don't, so those lines are skipped. */
    private const TYPES = ['-bool', '-boolean', '-int', '-integer', '-float', '-string'];

    /** One shell word: double-quoted, single-quoted or bare. */
    private const WORD = '/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|([^\s"\']+)/';

    /** @var array<string, list<string>> the write command for each item of the last scan, by subject key */
    private array $writes = [];

    /** @var array<string, true> the subjects of the last scan that had no value on this Mac */
    private array $unset = [];

    /**
     * @param  array<string, string>  $inherited  the environment dotsnitch was started with, for $HOME
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly PathResolver $paths,
        private readonly array $inherited,
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
     * Reads every setting with its own `defaults read`, about 10 ms each.
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        $this->writes = [];
        $this->unset = [];
        $items = [];

        foreach ($manifests as $manifest) {
            foreach ($this->writesIn($manifest, $log) as $write) {
                $setting = new Package($write['key'], $write['domain']);
                $current = $this->shell->run([$this->defaults(), 'read', $write['domain'], $write['key']]);
                $value = rtrim($current->output, "\n");

                if ($current->successful() && $this->matches($write, $value)) {
                    continue;
                }

                $script = basename($manifest);
                $this->writes[$setting->key()] = $write['command'];
                $this->unset[$setting->key()] = ! $current->successful();
                $items[$setting->key()] = new DriftItem($this->key(), DriftKind::Differs, $setting, $current->successful()
                    ? "is {$value}, {$script} writes {$write['value']}"
                    : "isn't set, {$script} writes {$write['value']}");
            }
        }

        $this->unset = array_filter($this->unset);

        return new DriftReport($this->key(), array_values($items));
    }

    public function actionsFor(DriftItem $item): array
    {
        return [
            Action::Write->value => Action::Write->label(),
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
            Action::Ignore, Action::Skip => [],
            default => throw new LogicException("macOS defaults can't {$decision->action->value}"),
        };
    }

    private function writeStep(DriftItem $item): Step
    {
        $setting = $item->subject->key();
        $command = $this->writes[$setting] ?? throw new RuntimeException("{$setting} wasn't in the last scan");

        return new Step(Step::commandLine($command), function () use ($command, $item, $setting) {
            $result = $this->shell->run($command);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput) ?: "Exited with code {$result->exitCode}");
            }

            return isset($this->unset[$setting])
                ? ['kind' => 'command', 'command' => $command, 'undo' => [[$this->defaults(), 'delete', $item->subject->type, $item->subject->name]], 'environment' => []]
                : ['kind' => 'command', 'command' => $command];
        });
    }

    /**
     * The lines it can compare: `defaults write domain key value`, with or without a type from
     * TYPES. A later line for the same domain and key wins, as it would when the script runs.
     * `sudo defaults write` lines are passed over, and so is -currentHost or -app.
     *
     * @param  callable(string): void  $log
     * @return array<string, array{domain: string, key: string, type: ?string, value: string, command: list<string>}>
     */
    private function writesIn(string $manifest, callable $log): array
    {
        $writes = [];
        $skipped = 0;

        foreach (file($manifest, FILE_IGNORE_NEW_LINES) as $line) {
            if (! preg_match('/^\s*defaults\s+write\s+(.+)$/', $line, $match)) {
                continue;
            }

            $words = $this->words($match[1]);

            if (in_array($words[0] ?? '', ['-g', '-globalDomain'], true)) {
                $words[0] = 'NSGlobalDomain';
            }

            $typed = count($words) === 4 && in_array($words[2], self::TYPES, true);
            $plain = count($words) === 3 && (is_numeric($words[2]) || ! str_starts_with($words[2], '-'));

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
            ];
        }

        if ($skipped > 0) {
            $log("{$skipped} defaults write ".($skipped === 1 ? 'line' : 'lines').' in '.basename($manifest)." can't be compared");
        }

        return $writes;
    }

    /**
     * Splits the rest of the line the way the shell would, as far as that goes without running
     * it: quotes come off, $HOME is filled in, and a trailing comment is dropped.
     *
     * @return list<string> empty for a line with any other $, a backtick, or a second command on it
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

            $word = str_replace(['${HOME}', '$HOME'], $home, $double ?? $bare);

            if (strpbrk($word, '$`') !== false || ($bare !== null && strpbrk($bare, ';&|') !== false)) {
                return [];
            }

            $words[] = $double === null ? $word : stripcslashes($word);
        }

        return $words;
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
