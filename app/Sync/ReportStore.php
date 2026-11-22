<?php

declare(strict_types=1);

namespace App\Sync;

use App\Support\JsonFile;
use DateTimeImmutable;
use RuntimeException;

/**
 * Keeps the last scan in last-report.json for the next run and for shell prompts, plus the last
 * few in reports/ for `dotsnitch history`. It runs before Laravel boots too (see QuietStatus), so it
 * can't use the container or helpers.
 */
final class ReportStore
{
    private const LATEST = 'last-report.json';

    private const NOTIFIED = 'last-notified.json';

    private const HISTORY = 'reports';

    private const HISTORY_KEPT = 30;

    /** The report this run compares against, read on the first save. */
    private ?SavedScan $baseline = null;

    private bool $baselineRead = false;

    /**
     * @param  string  $directory  created on the first save
     */
    public function __construct(private readonly string $directory) {}

    /**
     * $XDG_STATE_HOME/dotsnitch, or ~/.local/state/dotsnitch when that's unset.
     *
     * @param  array<string, string>  $environment  such as getenv()
     *
     * @throws RuntimeException when neither XDG_STATE_HOME nor HOME is set
     */
    public static function defaultDirectory(array $environment): string
    {
        $stateHome = $environment['XDG_STATE_HOME'] ?? '';

        if ($stateHome === '') {
            $home = $environment['HOME'] ?? '';
            $stateHome = $home === '' ? throw new RuntimeException('HOME is not set') : "{$home}/.local/state";
        }

        return "{$stateHome}/dotsnitch";
    }

    /**
     * Marks items the last saved scan didn't have as new and saves this scan over it. A second
     * save in the same run, such as the rescan after changes, compares with that same last scan,
     * so it doesn't lose what was new. A last scan of another repo doesn't count, so switching
     * --repo doesn't make every item new.
     *
     * A check that failed keeps what the last scan had for it, so the prompt count doesn't drop
     * and its next good scan still has something to compare with.
     *
     * @param  array<array-key, DriftReport|RuntimeException>  $reports  a failure keyed by its check
     */
    public function save(string $repo, array $reports): SavedScan
    {
        if (! $this->baselineRead) {
            $latest = $this->readableLatest();
            $this->baseline = $latest?->repo === $repo ? $latest : null;
            $this->baselineRead = true;
        }

        $checks = [];
        $new = [];

        foreach ($reports as $key => $report) {
            if ($report instanceof RuntimeException) {
                if (isset($this->baseline?->checks[$key])) {
                    $checks[$key] = $this->baseline->checks[$key];
                }

                continue;
            }

            $checks[$report->check] = array_map(fn (DriftItem $item) => $item->key(), $report->items);
            $before = $this->baseline?->checks[$report->check] ?? null;

            // A check the last scan skipped has nothing to compare with, so nothing in it is new.
            if ($before !== null) {
                $new[$report->check] = array_values(array_diff($checks[$report->check], $before));
            }
        }

        // Whole seconds, the way the file keeps it, so latest() returns an equal scan.
        $scan = new SavedScan(new DateTimeImmutable(date(DATE_ATOM)), $repo, $checks, $new, $this->baseline?->scannedAt);
        JsonFile::write($this->directory.'/'.self::LATEST, $scan->toArray());
        $this->saveHistory($scan);

        return $scan;
    }

    /**
     * @return ?SavedScan null before the first scan
     *
     * @throws RuntimeException when the file isn't a saved report
     */
    public function latest(): ?SavedScan
    {
        $data = JsonFile::read($this->directory.'/'.self::LATEST);

        return $data === null ? null : SavedScan::fromArray($data);
    }

    /**
     * latest(), or null when the file is broken. The scan being saved replaces it.
     */
    public function readableLatest(): ?SavedScan
    {
        try {
            return $this->latest();
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return list<SavedScan> the scans still kept, newest first, without any that can't be read
     */
    public function history(): array
    {
        $files = glob($this->directory.'/'.self::HISTORY.'/*.json') ?: [];
        rsort($files);
        $scans = [];

        foreach ($files as $file) {
            try {
                $data = JsonFile::read($file);
            } catch (RuntimeException) {
                continue;
            }

            if ($data !== null) {
                try {
                    $scans[] = SavedScan::fromArray($data);
                } catch (RuntimeException) {
                    continue;
                }
            }
        }

        return $scans;
    }

    /**
     * What the last --notify run saw, so the next one posts only what came after it, however
     * many scans ran in between.
     *
     * @return ?array<string, list<string>> item keys by check; null before the first --notify,
     *                                      or when the file is broken
     */
    public function notified(): ?array
    {
        try {
            $data = JsonFile::read($this->directory.'/'.self::NOTIFIED);
        } catch (RuntimeException) {
            return null;
        }

        return $data === null ? null : SavedScan::keysByCheck($data);
    }

    /**
     * @param  array<string, list<string>>  $keysByCheck
     *
     * @throws RuntimeException when the file can't be written
     */
    public function saveNotified(array $keysByCheck): void
    {
        JsonFile::write($this->directory.'/'.self::NOTIFIED, $keysByCheck);
    }

    /**
     * Saves a copy named by time, then drops the oldest past HISTORY_KEPT. The names sort by time.
     */
    private function saveHistory(SavedScan $scan): void
    {
        $directory = $this->directory.'/'.self::HISTORY;
        JsonFile::write($directory.'/'.$scan->scannedAt->format('Y-m-d\THis').'.json', $scan->toArray());
        JsonFile::keepNewest($directory, self::HISTORY_KEPT);
    }
}
