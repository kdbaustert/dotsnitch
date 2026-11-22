<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Contracts\ComparesSides;
use App\Contracts\Shell;
use App\Contracts\ShowsDiff;
use App\Contracts\SyncTarget;
use App\Data\Check;
use App\Data\Link;
use App\Support\ManifestEditor;
use App\Support\Manifests;
use App\Support\PathResolver;
use App\Sync\Action;
use App\Sync\Change;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftReport;
use App\Sync\IgnoreList;
use App\Sync\ResolutionMode;
use App\Sync\Step;
use App\Sync\SyncLog;
use Closure;
use Laravel\Prompts\Progress;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

/**
 * Turns drift into decisions, shows the plan, and runs it. Call resolve() after ScansChecks has
 * scanned, since the plan reads the checks and repo it found.
 *
 * @mixin Command
 */
trait ResolvesDrift
{
    use ScansChecks;

    /** @var list<string> what couldn't be planned or run, shown once at the end */
    private array $failures = [];

    /**
     * Asks what to do about the drift in these reports, then does it. --dry-run stops after the plan.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports  keyed by check
     * @return int failure for report-only, or when anything couldn't be planned or run
     */
    private function resolve(array $reports): int
    {
        $mode = $this->resolutionMode();

        if ($mode === ResolutionMode::Report) {
            return Command::FAILURE;
        }

        $items = $this->actionableItems($reports);
        $decisions = $mode === ResolutionMode::Each ? $this->decideEach($items) : $this->decideByGroup($items);
        $changes = $this->planned($this->withManifests($this->withoutSkips($decisions)));

        if ($changes === []) {
            $this->info('Nothing to change.');

            return $this->failureReport();
        }

        $this->showPlan($changes);
        $this->showSteps($changes);

        if ($this->option('dry-run')) {
            return $this->failureReport();
        }

        if (! confirm('Apply these changes?', default: true)) {
            return Command::SUCCESS;
        }

        $confirmed = $this->withUninstallsConfirmed($changes);
        $applied = $this->apply($confirmed);
        $this->showApplied(count($applied), count($confirmed));

        if ($applied !== []) {
            $this->saveRescan($reports, $applied);
            $this->offerCommit($applied);
        }

        return $this->failureReport();
    }

    /**
     * Without a terminal there's nobody to ask, so a plain run only reports. --dry-run still shows
     * what a quick review with every default would do.
     */
    private function resolutionMode(): ResolutionMode
    {
        if (! $this->input->isInteractive()) {
            return $this->option('dry-run') ? ResolutionMode::Review : ResolutionMode::Report;
        }

        $labels = [];

        foreach (ResolutionMode::cases() as $mode) {
            $labels[$mode->value] = $mode->label();
        }

        return ResolutionMode::from(select('How do you want to resolve it?', $labels, default: ResolutionMode::Review->value));
    }

    /**
     * Leaves out failed checks and items with nothing to offer but Skip, such as version
     * mismatches.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports
     * @return list<DriftItem>
     */
    private function actionableItems(array $reports): array
    {
        $items = [];

        foreach ($reports as $report) {
            if ($report instanceof DriftReport) {
                array_push($items, ...$report->items);
            }
        }

        return array_values(array_filter($items, fn (DriftItem $item) => $this->suggestionFor($item) !== Action::Skip));
    }

    /**
     * One multiselect per group of items that share a check, kind and suggestion, all ticked,
     * then one select for whatever was unticked.
     *
     * @param  list<DriftItem>  $items
     * @return list<Decision>
     */
    private function decideByGroup(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $groups["{$item->check}:{$item->kind->value}:{$this->suggestionFor($item)->value}"][] = $item;
        }

        $decisions = [];

        foreach ($groups as $group) {
            array_push($decisions, ...$this->decideGroup($group));
        }

        return $decisions;
    }

    /**
     * @param  non-empty-list<DriftItem>  $group  same check, kind and suggestion
     * @return list<Decision>
     */
    private function decideGroup(array $group): array
    {
        $first = $group[0];
        $suggestion = $this->suggestionFor($first);
        $options = [];

        foreach ($group as $item) {
            $options[$item->subject->key()] = $this->describe($item);
        }

        $picked = multiselect(
            "{$this->targetOf($first)->label()}, {$first->kind->label()}: pick the ones to ".lcfirst($suggestion->label()),
            $options,
            default: array_keys($options),
            scroll: 10,
            hint: 'Anything you untick gets one choice next.',
        );

        $decisions = [];
        $rest = [];

        foreach ($group as $item) {
            if (in_array($item->subject->key(), $picked, true)) {
                $decisions[] = new Decision($item, $suggestion);
            } else {
                $rest[] = $item;
            }
        }

        $action = $this->restAction($rest, $suggestion);

        foreach ($rest as $item) {
            $decisions[] = new Decision($item, $action);
        }

        return $decisions;
    }

    /**
     * What happens to the unticked items. Only actions they all offer are listed, and Skip is the
     * default.
     *
     * @param  list<DriftItem>  $rest
     */
    private function restAction(array $rest, Action $suggestion): Action
    {
        if ($rest === []) {
            return Action::Skip;
        }

        $choices = array_intersect_key(...array_map(fn (DriftItem $item) => $this->actionsFor($item), $rest));
        unset($choices[$suggestion->value]);

        if (count($choices) === 1) {
            return Action::Skip;
        }

        $count = count($rest);

        return $this->askUntilDecided(
            fn () => select("What about the {$count} you unticked?", $choices, default: Action::Skip->value),
            $rest,
        );
    }

    /**
     * One select per item, the suggestion first.
     *
     * @param  list<DriftItem>  $items
     * @return list<Decision>
     */
    private function decideEach(array $items): array
    {
        return array_map(fn (DriftItem $item) => new Decision($item, $this->decideOne($item)), $items);
    }

    /**
     * The question names the check, the kind and the item, and the item's detail goes in the
     * hint. The suggestion starts selected.
     */
    private function decideOne(DriftItem $item): Action
    {
        return $this->askUntilDecided(fn () => select(
            "{$this->targetOf($item)->label()}, {$item->kind->label()}: {$item->subject->key()}",
            $this->actionsFor($item),
            default: $this->suggestionFor($item)->value,
            hint: $item->detail ?? '',
        ), [$item]);
    }

    /**
     * Show diff isn't a decision: it prints each item's diff and asks again.
     *
     * @param  Closure(): string  $ask  returns an Action value
     * @param  list<DriftItem>  $items  the items the answer applies to
     */
    private function askUntilDecided(Closure $ask, array $items): Action
    {
        while (($action = Action::from($ask())) === Action::ShowDiff) {
            foreach ($items as $item) {
                $target = $this->targetOf($item);

                if ($target instanceof ShowsDiff) {
                    $this->output->writeln($target->diff($item), OutputInterface::OUTPUT_RAW);
                }
            }
        }

        return $action;
    }

    /**
     * Drops skips. A package can show up twice, such as missing and deprecated, so the later
     * choice that isn't a skip wins.
     *
     * @param  list<Decision>  $decisions
     * @return list<Decision>
     */
    private function withoutSkips(array $decisions): array
    {
        $kept = [];

        foreach ($decisions as $decision) {
            if ($decision->action !== Action::Skip) {
                $kept["{$decision->item->check}:{$decision->item->subject->key()}"] = $decision;
            }
        }

        return array_values($kept);
    }

    /**
     * Fills in the files each decision edits. Adding asks for the file once per check.
     *
     * @param  list<Decision>  $decisions
     * @return list<Decision>
     */
    private function withManifests(array $decisions): array
    {
        $destinations = [];

        return array_map(function (Decision $decision) use (&$destinations) {
            $check = $this->checksByKey[$decision->item->check];
            $manifests = match ($decision->action) {
                Action::AddToManifest => $destinations[$check->key()] ??= $this->destinationFor($check),
                Action::RemoveFromManifest, Action::Replace => array_map(fn (string $file) => "{$this->root}/{$file}", $check->manifests),
                default => [],
            };

            return new Decision($decision->item, $decision->action, $manifests);
        }, $decisions);
    }

    /**
     * The shared base file or this Mac's overlay, which is created if it doesn't exist yet. A
     * setup script's overlay isn't, since an empty script has no array to add to. With no
     * manifest at all, the check's first plain file name at the repo root.
     *
     * @return list<string> one absolute path, or none when there's nowhere sensible to add to
     */
    private function destinationFor(Check $check): array
    {
        $suffix = '.'.strtolower($this->hostname);
        $isOverlay = fn (string $file) => str_ends_with(strtolower($file), $suffix);
        $base = array_values(array_filter($check->manifests, fn (string $file) => ! $isOverlay($file)))[0] ?? null;
        $overlay = array_values(array_filter($check->manifests, $isOverlay))[0] ?? null;

        if ($base === null) {
            $fallback = $overlay ?? $check->target->manifestPatterns()[0] ?? '*';

            return strpbrk($fallback, '*?[{') === false ? ["{$this->root}/{$fallback}"] : [];
        }

        if ($overlay === null && str_ends_with($base, '.sh')) {
            return ["{$this->root}/{$base}"];
        }

        $overlay ??= "{$base}.{$this->hostname}";
        $file = select("Add {$check->target->label()} items to which file?", [
            $base => "{$base} (every Mac)",
            $overlay => "{$overlay} (this Mac only)",
        ], default: $base);

        return ["{$this->root}/{$file}"];
    }

    /**
     * A decision the target can't carry out is left out of the plan and reported at the end.
     *
     * @param  list<Decision>  $decisions
     * @return list<Change>
     */
    private function planned(array $decisions): array
    {
        $changes = [];

        foreach ($decisions as $decision) {
            try {
                $steps = $decision->action === Action::Ignore
                    ? [$this->ignoreStep($decision->item)]
                    : $this->targetOf($decision->item)->steps($decision);
                $changes[] = new Change($decision, $steps);
            } catch (RuntimeException $e) {
                $this->failures[] = "{$this->describe($decision->item)}: {$e->getMessage()}";
            }
        }

        return $changes;
    }

    /**
     * Hides the subject from this Mac's reports until `config --ignored` takes it off the list.
     */
    private function ignoreStep(DriftItem $item): Step
    {
        return new Step("Ignore {$item->subject->key()} on this Mac", function () use ($item) {
            $this->laravel->make(IgnoreList::class)->add($item);

            return ['kind' => 'ignore', 'check' => $item->check, 'key' => $item->subject->key()];
        });
    }

    /**
     * One row per change, with the ones that uninstall something in red. The table strips color
     * tags, so the red goes in as escape codes, and only when the terminal shows color.
     *
     * @param  list<Change>  $changes
     */
    private function showPlan(array $changes): void
    {
        table(['Check', 'Item', 'Change'], array_map(function (Change $change) {
            $row = [
                $this->targetOf($change->decision->item)->label(),
                $change->decision->item->subject->key(),
                $this->actionsFor($change->decision->item)[$change->decision->action->value],
            ];

            return $this->uninstalls($change) && $this->output->isDecorated()
                ? array_map(fn (string $cell) => "\e[31m{$cell}\e[39m", $row)
                : $row;
        }, $changes));
    }

    /**
     * The exact commands and edits, shown before the confirm so it's clear what a yes runs.
     *
     * @param  list<Change>  $changes
     */
    private function showSteps(array $changes): void
    {
        $this->line($this->option('dry-run') ? 'Dry run. These would run, in order:' : 'These will run, in order:');

        foreach ($changes as $change) {
            foreach ($change->steps as $step) {
                $this->line("  {$step->description}");
            }
        }
    }

    /**
     * Uninstalling is the one change a backup can't undo, so it gets its own confirm that
     * defaults to no. Saying no drops only those changes.
     *
     * @param  list<Change>  $changes
     * @return list<Change>
     */
    private function withUninstallsConfirmed(array $changes): array
    {
        $names = array_map(fn (Change $change) => $change->decision->item->subject->key(), array_filter($changes, $this->uninstalls(...)));

        if ($names === [] || confirm('Uninstall '.implode(', ', $names).'?', default: false, hint: 'A replace uninstalls the old package once its successor is in.')) {
            return $changes;
        }

        return array_values(array_filter($changes, fn (Change $change) => ! $this->uninstalls($change)));
    }

    /**
     * A replace counts: it uninstalls the old package once its successor is in.
     */
    private function uninstalls(Change $change): bool
    {
        return in_array($change->decision->action, [Action::Uninstall, Action::Replace], true);
    }

    /**
     * Runs each change under a progress bar, logging every step. A failed step stops the rest of
     * its change, and the other changes still run.
     *
     * @param  list<Change>  $changes
     * @return list<Change> the ones that finished
     */
    private function apply(array $changes): array
    {
        if ($changes === []) {
            return [];
        }

        $log = $this->laravel->make(SyncLog::class);
        $results = progress('Applying changes', $changes, function (Change $change, Progress $progress) use ($log) {
            $progress->hint($this->describe($change->decision->item));

            foreach ($change->steps as $step) {
                try {
                    $effect = ($step->run)();
                } catch (RuntimeException $e) {
                    $log->failed($change->decision, $step, $e->getMessage());
                    $this->failures[] = "{$step->description}: {$e->getMessage()}";

                    return null;
                }

                $log->ran($change->decision, $step, $effect);
            }

            return $change;
        });

        return array_values(array_filter($results));
    }

    /**
     * Such as "Applied 4 of 5 changes.", then where this run's backups and its record are.
     *
     * @param  int  $planned  0 when every change was an uninstall that got turned down
     */
    private function showApplied(int $applied, int $planned): void
    {
        if ($planned === 0) {
            return;
        }

        $this->line($applied === $planned
            ? "Applied {$applied} ".($applied === 1 ? 'change' : 'changes').'.'
            : "Applied {$applied} of {$planned} changes.");

        $backups = $this->laravel->make(ManifestEditor::class)->backupFolder();
        $record = $this->laravel->make(SyncLog::class)->path();

        if ($backups !== null) {
            $this->line("Backups are in {$backups}.");
        }

        if ($record !== null) {
            $this->line("What it changed is recorded in {$record}.");
        }
    }

    /**
     * Scans the checks that changed again, picking up an overlay the run created, saves the
     * report for next time and shows it. An ignore doesn't need a rescan, just the filter again.
     *
     * @param  array<string, DriftReport|RuntimeException>  $reports
     * @param  list<Change>  $applied
     */
    private function saveRescan(array $reports, array $applied): void
    {
        $ignores = $this->laravel->make(IgnoreList::class);
        $reports = array_map(fn ($report) => $report instanceof DriftReport ? $ignores->apply($report) : $report, $reports);
        $changedChecks = array_values(array_unique(array_map(
            fn (Change $change) => $change->decision->item->check,
            array_filter($applied, fn (Change $change) => $change->decision->action !== Action::Ignore),
        )));

        if ($changedChecks !== []) {
            $chosen = $this->laravel->make(Manifests::class)->only($changedChecks)->chosen($this->root, $this->hostname);

            foreach ($changedChecks as $key) {
                $this->checksByKey[$key] = new Check($this->checksByKey[$key]->target, $chosen[$key], true);
                $reports[$key] = $this->scan($this->checksByKey[$key]);
            }
        }

        $saved = $this->saveScan($reports);

        if ($changedChecks !== []) {
            $this->report($reports, $saved);
        }
    }

    /**
     * When the repo is inside a git checkout, shows what changed in it and offers to commit just
     * those files. Defaults to no.
     *
     * @param  list<Change>  $applied
     */
    private function offerCommit(array $applied): void
    {
        $root = realpath($this->root) ?: $this->root;
        $files = [];

        foreach ($this->laravel->make(ManifestEditor::class)->changed() as $file) {
            if (str_starts_with($file, "{$root}/")) {
                $files[] = substr($file, strlen($root) + 1);
            }
        }

        $git = $this->laravel->make(PathResolver::class)->find('git');

        if ($files === [] || $git === null) {
            return;
        }

        $shell = $this->laravel->make(Shell::class);

        // Asks git, since the dotfiles can be a folder inside a bigger checkout with no .git of its own.
        if (! $shell->run([$git, '-C', $root, 'rev-parse', '--is-inside-work-tree'])->successful()) {
            return;
        }

        $this->line(rtrim($shell->run([$git, '-C', $root, 'diff', '--stat', '--', ...$files])->output));

        if (! confirm('Commit these changes to your dotfiles?', default: false, hint: $this->commitMessage($applied))) {
            return;
        }

        foreach ([['add', '--', ...$files], ['commit', '-m', $this->commitMessage($applied), '--', ...$files]] as $arguments) {
            $result = $shell->run([$git, '-C', $root, ...$arguments]);

            if (! $result->successful()) {
                $this->failures[] = "git {$arguments[0]}: ".(trim($result->errorOutput) ?: trim($result->output));

                return;
            }
        }

        $this->laravel->make(SyncLog::class)->committed($root, $this->commitMessage($applied));
    }

    /**
     * Such as "dotsnitch: add jq, remove mpv, replace youtube-dl, adopt .zshrc".
     *
     * @param  list<Change>  $applied
     */
    private function commitMessage(array $applied): string
    {
        $verbs = [
            Action::AddToManifest->value => 'add',
            Action::RemoveFromManifest->value => 'remove',
            Action::Replace->value => 'replace',
            Action::Adopt->value => 'adopt',
        ];
        $parts = [];

        foreach ($applied as $change) {
            $verb = $verbs[$change->decision->action->value] ?? null;
            $subject = $change->decision->item->subject;

            if ($verb !== null) {
                $parts[] = $verb.' '.($subject instanceof Link ? basename($subject->source) : $subject->name);
            }
        }

        return 'dotsnitch: '.implode(', ', $parts);
    }

    /**
     * Lists what couldn't be planned or run, with the command's error.
     *
     * @return int failure when there was anything to list
     */
    private function failureReport(): int
    {
        if ($this->failures === []) {
            return Command::SUCCESS;
        }

        $this->error(count($this->failures).' '.(count($this->failures) === 1 ? 'change' : 'changes').' failed:');

        foreach ($this->failures as $failure) {
            $this->line("  {$failure}");
        }

        return Command::FAILURE;
    }

    /**
     * @return array<string, string> Action value => label, from the check that reported the item
     */
    private function actionsFor(DriftItem $item): array
    {
        return $this->targetOf($item)->actionsFor($item);
    }

    /**
     * The first action the check offers, which is its suggested fix.
     */
    private function suggestionFor(DriftItem $item): Action
    {
        return Action::from(array_key_first($this->actionsFor($item)));
    }

    /**
     * Looked up among the checks this run scanned, so the item has to come from one of its reports.
     */
    private function targetOf(DriftItem $item): SyncTarget
    {
        return $this->checksByKey[$item->check]->target;
    }

    /**
     * Takes the place of the one in ScansChecks. After the detail come what the dotfiles and this
     * Mac each have, for a check that can tell, so it's clear which side is out of step.
     *
     * @return list<string>
     */
    private function notesOn(DriftItem $item): array
    {
        $notes = $item->detail === null ? [] : [$item->detail];
        $target = $this->targetOf($item);

        if ($target instanceof ComparesSides) {
            $sides = $target->sides($item);
            array_push($notes, "dotfiles  {$sides['dotfiles']}", "this Mac  {$sides['mac']}");
        }

        return $notes;
    }

    /**
     * Such as "formula:youtube-dl (deprecated, use `yt-dlp` instead)".
     */
    private function describe(DriftItem $item): string
    {
        return $item->subject->key().($item->detail === null ? '' : " ({$item->detail})");
    }
}
