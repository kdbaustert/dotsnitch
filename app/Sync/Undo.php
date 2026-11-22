<?php

declare(strict_types=1);

namespace App\Sync;

use App\Support\AtomicFile;
use RuntimeException;

/**
 * Turns the steps of a sync log into the steps that take them back. Files and links only: a
 * command that installed or removed a package has no step here.
 *
 * Each step looks at the path again before it touches it, and fails when the path isn't how the
 * sync left it.
 */
final class Undo
{
    public function __construct(private readonly IgnoreList $ignores) {}

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
     * @param  list<array<string, mixed>>  $logged
     * @return list<string> the commands the sync ran, which stay as they are
     */
    public function commands(array $logged): array
    {
        $commands = [];

        foreach ($logged as $entry) {
            if (($entry['effect']['kind'] ?? null) === 'command') {
                $commands[] = Step::commandLine($entry['effect']['command']);
            }
        }

        return $commands;
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
