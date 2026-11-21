<?php

declare(strict_types=1);

namespace App\Support;

use App\Sync\ReportStore;
use App\Sync\SavedScan;
use Closure;
use Throwable;

/**
 * `status --quiet` for shell prompts. The entry script runs it before Laravel boots, since a
 * prompt runs it on every Enter. It prints the saved count and, when the report is old, starts a
 * real status scan in the background for the next prompt to read.
 */
final class QuietStatus
{
    private const DEFAULT_MAX_AGE = 86400;

    /** Seconds per --max-age unit. A bare number is seconds. */
    private const UNITS = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    /** Options that only mean something to the quiet path, so the refresh doesn't get them. */
    private const QUIET_ONLY = ['--quiet', '-q', '--new', '--max-age'];

    /** Options that can take their value as the next argument, such as `--repo ~/dotfiles`. */
    private const VALUE_OPTIONS = ['--repo', '--checks', '--max-age', '--env'];

    private readonly Closure $spawn;

    /**
     * @param  list<string>  $argv  as the entry script got it, script path first
     * @param  array<string, string>  $environment  such as getenv()
     * @param  ?Closure(list<string>): void  $spawn  starts the refresh without waiting for it;
     *                                               tests pass one that records the command
     */
    public function __construct(
        private readonly array $argv,
        private readonly array $environment,
        ?Closure $spawn = null,
    ) {
        $this->spawn = $spawn ?? self::spawnDetached(...);
    }

    /**
     * True for `dotsnitch status --quiet` and `dotsnitch status -q`, wherever the flag sits.
     *
     * @param  list<string>  $argv
     */
    public static function handles(array $argv): bool
    {
        return self::commandName($argv) === 'status' && array_intersect($argv, ['--quiet', '-q']) !== [];
    }

    /**
     * The first argument that isn't an option or an option's value.
     *
     * @param  list<string>  $argv
     */
    private static function commandName(array $argv): ?string
    {
        $isValue = false;

        foreach (array_slice($argv, 1) as $arg) {
            if (! $isValue && ! str_starts_with($arg, '-')) {
                return $arg;
            }

            $isValue = ! $isValue && in_array($arg, self::VALUE_OPTIONS, true);
        }

        return null;
    }

    /**
     * Prints the count, or nothing when it's 0, there's no report yet, or anything goes wrong. A
     * prompt has nowhere to show an error, and a broken prompt is worse than a missing count. A
     * broken report counts as missing, so the refresh replaces it.
     */
    public function run(): void
    {
        try {
            $directory = ReportStore::defaultDirectory($this->environment);
            $saved = (new ReportStore($directory))->readableLatest();
            $count = in_array('--new', $this->argv, true) ? $saved?->newCount() : $saved?->count();

            if ($count > 0) {
                echo "{$count}\n";
            }

            $this->refreshWhenStale($saved, $directory.'/'.StatusLock::FILE);
        } catch (Throwable) {
            // Nothing to print, as above.
        }
    }

    /**
     * Starts a refresh when the report is missing or older than --max-age, unless one is running.
     * The lock file's time marks the last start, so a refresh that keeps failing is retried once
     * per --max-age, not on every prompt.
     */
    private function refreshWhenStale(?SavedScan $saved, string $lockPath): void
    {
        $maxAge = $this->maxAge();
        $isOld = fn (int $time) => time() - $time >= $maxAge;
        $reportIsFresh = $saved !== null && ! $isOld($saved->scannedAt->getTimestamp());
        $startedRecently = is_file($lockPath) && ! $isOld((int) filemtime($lockPath));

        if ($reportIsFresh || $startedRecently || (new StatusLock($lockPath))->isHeld()) {
            return;
        }

        touch($lockPath);
        ($this->spawn)([...SelfPath::command(), ...$this->refreshArguments()]);
    }

    /**
     * status with whatever else was passed, such as --repo=, minus the options only --quiet reads.
     *
     * @return list<string>
     */
    private function refreshArguments(): array
    {
        $arguments = [];
        $skipValue = false;

        foreach (array_slice($this->argv, 1) as $arg) {
            if ($skipValue) {
                $skipValue = false;

                continue;
            }

            $name = explode('=', $arg, 2)[0];

            if (in_array($name, self::QUIET_ONLY, true)) {
                // --max-age 6h passes the value as its own argument.
                $skipValue = $arg === '--max-age';

                continue;
            }

            $arguments[] = $arg;
        }

        return [...$arguments, '--no-interaction'];
    }

    /**
     * --max-age in seconds, from 30m, 6h, 2d or a bare number of seconds. Anything else is 24h,
     * since a prompt can't report a typo.
     */
    private function maxAge(): int
    {
        $value = null;

        foreach ($this->argv as $index => $arg) {
            if (str_starts_with($arg, '--max-age=')) {
                $value = substr($arg, strlen('--max-age='));
            } elseif ($arg === '--max-age') {
                $value = $this->argv[$index + 1] ?? null;
            }
        }

        if ($value === null || preg_match('/^(\d+)([smhd]?)$/', $value, $match) !== 1) {
            return self::DEFAULT_MAX_AGE;
        }

        return (int) $match[1] * self::UNITS[$match[2]];
    }

    /**
     * nohup and & so the refresh outlives the prompt and the prompt doesn't wait for it.
     *
     * @param  list<string>  $command
     */
    private static function spawnDetached(array $command): void
    {
        exec('nohup '.implode(' ', array_map(escapeshellarg(...), $command)).' >/dev/null 2>&1 &');
    }
}
