<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ScansChecks;
use App\Data\Check;
use App\Data\Package;
use App\Support\LaunchAgent;
use App\Support\Notifier;
use App\Support\StatusLock;
use App\Sync\DriftItem;
use App\Sync\DriftReport;
use App\Sync\ReportStore;
use App\Sync\SavedScan;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\warning;

final class StatusCommand extends Command
{
    use ScansChecks;

    /** How many names a notification lists before "and N more". */
    private const NAMES_SHOWN = 3;

    /** How long the daily run waits for another status to finish. */
    private const NOTIFY_WAIT_SECONDS = 300;

    // --quiet never gets here: the entry script answers it from the saved report (see QuietStatus).
    protected $signature = 'status
        {--repo= : Path to your dotfiles directory}
        {--checks= : Checks to run, comma-separated (such as brew,npm); defaults to every one that\'s installed and has a manifest}
        {--new : List only what the last scan didn\'t have}
        {--notify : Post a macOS notification when anything is new}
        {--json : Print the scan as JSON and nothing else}
        {--max-age=24h : With --quiet, how old the saved report can get before a refresh starts in the background}';

    protected $description = 'Scan without asking anything and show what is out of sync. With --quiet, print the saved count for a shell prompt';

    /**
     * Status never asks anything, even in a terminal, so a schedule or a prompt refresh can't
     * hang on a question.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $input->setInteractive(false);

        return parent::run($input, $output);
    }

    /**
     * Scans, saves the report for the next run and shell prompts, prints it with where to go
     * next, and with --notify posts what's new. With --json the scan is all it prints.
     *
     * @return int failure when a check couldn't finish, or --notify or --json couldn't get the lock. Drift

     *             alone isn't a failure, so a schedule's log doesn't show every day as an error.
     *
     * @throws InvalidArgumentException when --checks names a check that doesn't exist or can't run
     */
    public function handle(StatusLock $lock): int
    {
        // --notify is how the daily run starts, so it looks after the daily run's log.
        $notify = (bool) $this->option('notify');

        if ($notify) {
            $this->laravel->make(LaunchAgent::class)->rotateLog();
        }

        // The daily run waits out a prompt's refresh instead of skipping the day.
        if (! $lock->acquire($notify ? self::NOTIFY_WAIT_SECONDS : 0)) {
            $this->line('Another dotsnitch status or sync is already running.');

            // A daily run that waited and still couldn't scan shows as a failed run in launchd,
            // and a script reading --json gets no JSON.
            return $notify || $this->option('json') ? self::FAILURE : self::SUCCESS;
        }

        // The spinner, the task lines and any warning go through the same output as the JSON.
        if ($this->option('json')) {
            $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }

        $checks = $this->allChecks();
        $selected = $this->option('checks') === null
            ? array_values(array_filter($checks, fn (Check $check) => $check->isReady()))
            : $this->checksFromOption($checks);

        $reports = $this->scanEach($selected);
        $saved = $this->saveScan($reports);
        $result = $this->option('json') ? $this->printJson($reports, $saved) : $this->report($reports, $saved);

        if ($this->itemsOf($reports) !== [] && ! $this->option('json')) {
            $this->newLine();
            $this->line('Go through them with dotsnitch sync.');
        }

        if ($notify) {
            $this->notifyNew($reports, $saved);
        }

        return $result;
    }

    /**
     * A check that failed has its error and no items.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports
     * @return int failure when any check couldn't finish
     */
    private function printJson(array $reports, SavedScan $saved): int
    {
        $checks = [];

        foreach ($reports as $key => $report) {
            $finished = $report instanceof DriftReport;
            $checks[$key] = [
                'label' => $this->checksByKey[$key]->target->label(),
                'error' => $finished ? null : $report->getMessage(),
                'ignored' => $finished ? $report->ignored : 0,
                'items' => array_map(fn (DriftItem $item) => [
                    'kind' => $item->kind->value,
                    'item' => $item->subject->key(),
                    'detail' => $item->detail,
                    'new' => $saved->isNew($item),
                ], $finished ? $report->items : []),
            ];
        }

        $this->output->writeln(json_encode([
            'scanned_at' => $saved->scannedAt->format(DATE_ATOM),
            'repo' => $this->root,
            'checks' => $checks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return count($this->finished($reports)) < count($reports) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Posts what the last --notify run didn't see, so drift a sync or a prompt's refresh found
     * first still gets posted, and the same drift doesn't nag every day. A check the last
     * --notify didn't run compares with the scan before instead. A failed post is logged,
     * doesn't fail the run, and leaves the items for the next run.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports
     */
    private function notifyNew(array $reports, SavedScan $saved): void
    {
        $store = $this->laravel->make(ReportStore::class);
        $notified = $store->notified() ?? [];
        $new = array_values(array_filter($this->itemsOf($reports), fn (DriftItem $item) => isset($notified[$item->check])
            ? ! in_array($item->key(), $notified[$item->check], true)
            : $saved->isNew($item)));

        if ($new !== []) {
            try {
                $this->laravel->make(Notifier::class)->notify($this->newItemsMessage($new));
            } catch (RuntimeException $e) {
                warning("Couldn't post the notification: {$e->getMessage()}");

                return;
            }
        }

        // A failed check keeps what was posted for it before.
        $finished = $this->finished($reports);
        $store->saveNotified([...$notified, ...array_map(
            fn (DriftReport $report) => array_map(fn (DriftItem $item) => $item->key(), $report->items),
            $finished,
        )]);
    }

    /**
     * Such as "2 new items out of sync (jq, gh-dash)" or "5 new items out of sync (jq, gh, wget and 2 more)".
     *
     * @param  non-empty-list<DriftItem>  $new
     */
    private function newItemsMessage(array $new): string
    {
        $names = array_values(array_unique(array_map(
            fn (DriftItem $item) => $item->subject instanceof Package ? $item->subject->name : basename($item->subject->target),
            $new,
        )));
        $more = count($names) - self::NAMES_SHOWN;
        $listed = implode(', ', array_slice($names, 0, self::NAMES_SHOWN)).($more > 0 ? " and {$more} more" : '');
        $count = count($new);

        return "{$count} new ".($count === 1 ? 'item' : 'items')." out of sync ({$listed})";
    }
}
