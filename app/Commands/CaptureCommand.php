<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ResolvesDrift;
use App\Data\Check;
use App\Data\DefaultsSnapshot;
use App\Support\StatusLock;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Targets\MacDefaults;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\spin;

final class CaptureCommand extends Command
{
    use ResolvesDrift;

    protected $signature = 'capture
        {--repo= : Path to your dotfiles directory}
        {--dry-run : Show the lines it would write, and change nothing}
        {--current : List every setting as it is now, instead of waiting for changes}';

    protected $description = 'Write the macOS settings you change while it waits into your setup script';

    /**
     * Reads every setting, waits while you change some, then reads them all again. Each one that
     * changed and fits in one `defaults write` is offered: a new line for a setting the script
     * doesn't write yet, or the new value for one it does. Takes the status lock after the
     * second read, so a sync can't edit the script at the same time.
     *
     * With --current it doesn't wait, and offers every setting whose value isn't in the script
     * yet, none of them ticked. That takes two reads per setting, a few seconds in all.
     *
     * @return int failure without a terminal, when defaults can't read, when a sync or status run
     *             holds the lock, or when a change failed
     */
    public function handle(StatusLock $lock): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run it in a terminal. It waits while you change the settings.');

            return self::FAILURE;
        }

        $byKey = $this->byKey($this->allChecks());
        $defaults = $this->laravel->make(MacDefaults::class);
        $this->checksByKey = [$defaults->key() => new Check($defaults, $byKey[$defaults->key()]->manifests, $defaults->isAvailable())];

        if (! $defaults->isAvailable()) {
            $this->error('defaults isn\'t on the PATH, so there are no macOS settings to read.');

            return self::FAILURE;
        }

        try {
            if ($this->option('current')) {
                $before = new DefaultsSnapshot([]);
                $after = spin(fn () => $defaults->snapshot(), 'Reading every setting…');
            } else {
                $before = spin(fn () => $defaults->snapshot(), 'Reading every setting…');
                pause('Change the settings you want to keep, then press Enter.');
                $after = spin(fn () => $defaults->snapshot(), 'Reading them again…');
            }
        } catch (RuntimeException $e) {
            $this->error("defaults read failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $lock->acquire()) {
            $this->error('A dotsnitch sync or status run is going. Try again once it finishes.');

            return self::FAILURE;
        }

        $manifests = array_map(fn (string $file) => "{$this->root}/{$file}", $this->checksByKey[$defaults->key()]->manifests);
        [$items, $logged] = spin(function () use ($defaults, $before, $after, $manifests) {
            $logged = [];
            $items = $defaults->capture($before, $after, $manifests, function (string $line) use (&$logged) {
                $logged[] = $line;
            })->items;

            return [$items, $logged];
        }, 'Reading each setting that changed…');

        foreach ($logged as $line) {
            $this->line(OutputFormatter::escape($line));
        }

        if ($items === []) {
            $this->info('No setting changed that one line can write.');

            return self::SUCCESS;
        }

        $changes = $this->planned($this->withManifests($this->pick($items)));

        if ($changes === []) {
            $this->info('Nothing to change.');

            return $this->failureReport();
        }

        $this->showPlan($changes);
        $this->showSteps($changes);

        // A change that couldn't be planned still fails the run after a no.
        if ($this->option('dry-run') || ! confirm('Apply these changes?', default: true)) {
            return $this->failureReport();
        }

        $uncommitted = $this->uncommittedFiles();
        $applied = $this->apply($changes);
        $this->showApplied(count($applied), count($changes));

        if ($applied !== []) {
            $this->offerCommit($applied, $uncommitted);
        }

        return $this->failureReport();
    }

    /**
     * One multiselect with every setting ticked, or none with --current, where most are ones
     * nobody changed. A setting the script doesn't write gets a line, and one it does gets its
     * value changed.
     *
     * @param  list<DriftItem>  $items
     * @return list<Decision>
     */
    private function pick(array $items): array
    {
        $options = [];

        foreach ($items as $item) {
            $options[$item->subject->key()] = $this->describe($item);
        }

        $picked = multiselect('Which settings should go in your dotfiles?', $options, default: $this->option('current') ? [] : array_keys($options), scroll: 10);
        $decisions = [];

        foreach ($items as $item) {
            if (in_array($item->subject->key(), $picked, true)) {
                $decisions[] = new Decision($item, $item->kind === DriftKind::Untracked ? Action::AddToManifest : Action::UpdateManifest);
            }
        }

        return $decisions;
    }
}
