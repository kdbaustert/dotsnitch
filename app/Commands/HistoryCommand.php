<?php

declare(strict_types=1);

namespace App\Commands;

use App\Sync\ReportStore;
use App\Sync\SavedScan;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\table;

final class HistoryCommand extends Command
{
    protected $signature = 'history';

    protected $description = 'Show how much was out of sync in each of the last scans';

    /**
     * One row per saved scan, newest first. Scans of another dotfiles folder are listed too, so
     * the folder is a column.
     */
    public function handle(ReportStore $store): int
    {
        $scans = $store->history();

        if ($scans === []) {
            $this->line('No scan has been saved yet. Run dotsnitch status.');

            return self::SUCCESS;
        }

        table(['Scanned', 'Out of sync', 'New', 'By check', 'Dotfiles'], array_map(fn (SavedScan $scan) => [
            $scan->scannedAt->format('M j, H:i'),
            (string) $scan->count(),
            (string) $scan->newCount(),
            $this->countsByCheck($scan),
            $scan->repo,
        ], $scans));

        return self::SUCCESS;
    }

    /**
     * Such as "brew 3, npm 1", leaving out the checks that were in sync.
     */
    private function countsByCheck(SavedScan $scan): string
    {
        $counts = [];

        foreach (array_filter($scan->checks) as $check => $keys) {
            $counts[] = $check.' '.count($keys);
        }

        return implode(', ', $counts);
    }
}
