<?php

declare(strict_types=1);

namespace App\Sync;

use App\Contracts\Shell;
use App\Support\AtomicFile;
use RuntimeException;

/**
 * Turns the steps of a sync log into the steps that take them back. Files and links come from
 * steps(), and installs and uninstalls from packageSteps(), so the caller chooses whether
 * packages are touched.
 *
 * Each file or link step looks at the path again before it touches it, and fails when the path
 * isn't how the sync left it.
 */
final class Undo
{
    private const COMMAND_TIMEOUT = 1800;

    public function __construct(
        private readonly IgnoreList $ignores,
        private readonly Shell $shell,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $logged  a log's steps, in the order they ran
     * @return list<Step> last change first
     */
    public function steps(array $logged): array
    {
        $steps = [];
        $restored = [];

        foreach (array_reverse($logged) as $entry) {
            $effect = $entry['effect'] ?? [];
            $kind = $effect['kind'] ?? null;

            // One backup covers every edit a run made to a file, and the last edit restores it.
            if ($kind === 'edit' && isset($restored[$effect['path']])) {
                continue;
            }

            $step = match ($kind) {
                'edit' => $this->restoreStep($effect['path'], $effect['backup'], $effect['sha256']),
                'link' => $this->unlinkStep($effect['target'], $effect['source']),
                'move' => $this->moveBackStep($effect['to'], $effect['from']),
                'repoint' => $this->repointStep($effect['target'], $effect['source'], $effect['pointed_to']),
                'unlink' => $this->linkStep($effect['target'], $effect['pointed_to']),
                'ignore' => $this->stopIgnoringStep($effect['check'], $effect['key']),
                default => null,
            };

            if ($kind === 'edit') {
                $restored[$effect['path']] = true;
            }

            if ($step !== null) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * The opposite of each install and uninstall the sync ran. An uninstalled package comes back
     * at the current version, not the one that was removed.
     *
     * @param  list<array<string, mixed>>  $logged
     * @return list<Step> last command first
     */
    public function packageSteps(array $logged): array
    {
        $steps = [];

        foreach (array_reverse($logged) as $entry) {
            $effect = $entry['effect'] ?? [];

            foreach ($effect['undo'] ?? [] as $command) {
                $steps[] = $this->commandStep($command, $effect['environment'] ?? []);
            }
        }

        return $steps;
    }

    /**
     * @param  list<array<string, mixed>>  $logged
     * @return list<string> every command the sync ran
     */
    public function commands(array $logged): array
    {
        return array_map(
            fn (array $entry) => Step::commandLine($entry['effect']['command']),
            $this->commandEntries($logged),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $logged
     * @return list<string> the commands packageSteps() has nothing for, such as a reinstall at a
     *                      pinned version, or any command in a record that predates package undo
     */
    public function oneWayCommands(array $logged): array
    {
        $oneWay = array_filter($this->commandEntries($logged), fn (array $entry) => ! isset($entry['effect']['undo']));

        return array_map(fn (array $entry) => Step::commandLine($entry['effect']['command']), array_values($oneWay));
    }

    /**
     * @param  list<array<string, mixed>>  $logged
     * @return list<array<string, mixed>>
     */
    private function commandEntries(array $logged): array
    {
        return array_values(array_filter($logged, fn (array $entry) => ($entry['effect']['kind'] ?? null) === 'command'));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function commandStep(array $command, array $environment): Step
    {
        return new Step(Step::commandLine($command), function () use ($command, $environment) {
            $result = $this->shell->run($command, self::COMMAND_TIMEOUT, $environment);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput) ?: trim($result->output) ?: "Exited with code {$result->exitCode}");
            }

            return [];
        });
    }

    /**
     * @param  ?string  $backup  null for a file the sync created, which is removed
     * @param  string  $sha256  of what the sync wrote
     */
    private function restoreStep(string $path, ?string $backup, string $sha256): Step
    {
        $description = $backup === null ? Step::commandLine(['rm', $path]) : Step::commandLine(['cp', $backup, $path]);

        return new Step($description, function () use ($path, $backup, $sha256) {
            if (! is_file($path) || hash_file('sha256', $path) !== $sha256) {
                throw new RuntimeException("{$path} changed since the sync");
            }

            if ($backup === null) {
                return @unlink($path) ? [] : throw new RuntimeException("Could not remove {$path}");
            }

            $before = @file_get_contents($backup);

            if ($before === false) {
                throw new RuntimeException("Could not read {$backup}");
            }

            AtomicFile::write($path, $before);

            return [];
        });
    }

    /**
     * Removes a link the sync made.
     */
    private function unlinkStep(string $target, string $source): Step
    {
        return new Step(Step::commandLine(['rm', $target]), function () use ($target, $source) {
            $this->mustPointAt($target, $source);

            return @unlink($target) ? [] : throw new RuntimeException("Could not remove {$target}");
        });
    }

    /**
     * Moves a file or folder back to where the sync took it from.
     */
    private function moveBackStep(string $from, string $to): Step
    {
        return new Step(Step::commandLine(['mv', $from, $to]), function () use ($from, $to) {
            if (! file_exists($from) && ! is_link($from)) {
                throw new RuntimeException("{$from} is gone");
            }

            $this->mustBeFree($to);
            AtomicFile::makeParentOf($to);

            return @rename($from, $to) ? [] : throw new RuntimeException("Could not move {$from} to {$to}");
        });
    }

    /**
     * Points a link back at where it went before the sync.
     */
    private function repointStep(string $target, string $source, string $pointedTo): Step
    {
        return new Step(Step::commandLine(['ln', '-sfn', $pointedTo, $target]), function () use ($target, $source, $pointedTo) {
            $this->mustPointAt($target, $source);

            return @unlink($target) && @symlink($pointedTo, $target)
                ? []
                : throw new RuntimeException("Could not repoint {$target}");
        });
    }

    /**
     * Puts back a link the sync removed.
     */
    private function linkStep(string $target, string $pointedTo): Step
    {
        return new Step(Step::commandLine(['ln', '-s', $pointedTo, $target]), function () use ($target, $pointedTo) {
            $this->mustBeFree($target);

            return @symlink($pointedTo, $target) ? [] : throw new RuntimeException("Could not link {$target} to {$pointedTo}");
        });
    }

    private function stopIgnoringStep(string $check, string $key): Step
    {
        return new Step("Stop ignoring {$key}", function () use ($check, $key) {
            $this->ignores->remove($check, [$key]);

            return [];
        });
    }

    /**
     * @throws RuntimeException when $link isn't the link the sync left
     */
    private function mustPointAt(string $link, string $destination): void
    {
        if (! is_link($link) || readlink($link) !== $destination) {
            throw new RuntimeException("{$link} changed since the sync");
        }
    }

    /**
     * @throws RuntimeException when something is at $path, even a broken link
     */
    private function mustBeFree(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException("{$path} already exists");
        }
    }
}
