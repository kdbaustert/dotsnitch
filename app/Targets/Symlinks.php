<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ScansWholeRepo;
use App\Contracts\Shell;
use App\Contracts\ShowsDiff;
use App\Contracts\SyncTarget;
use App\Data\Link;
use App\Support\AtomicFile;
use App\Support\DotfilesRepo;
use App\Support\LinkMapBuilder;
use App\Support\ManifestEditor;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\Step;
use LogicException;
use RuntimeException;

/**
 * Checks that every config file the repo expects in ~ is a symlink to it. LinkMapBuilder says
 * which links those are. Anything a link would replace is moved into the run's backup folder.
 */
final class Symlinks implements ScansWholeRepo, ShowsDiff, SyncTarget
{
    /** Seconds. A diff of two config folders is quick unless something is very wrong. */
    private const DIFF_TIMEOUT = 30;

    /** diff's exit code when the two sides differ. */
    private const DIFF_FOUND_DIFFERENCES = 1;

    /** What each kind of drift offers before Ignore and Skip, suggestion first. */
    private const ACTIONS = [
        DriftKind::NotLinked->value => [Action::Link],
        DriftKind::Conflict->value => [Action::BackUpAndLink, Action::ShowDiff, Action::Adopt],
        DriftKind::WrongTarget->value => [Action::Repoint],
        DriftKind::Broken->value => [Action::Unlink],
    ];

    /**
     * @param  array<string, string>  $inherited  the environment this process started with, for HOME
     */
    public function __construct(
        private readonly DotfilesRepo $repo,
        private readonly Shell $shell,
        private readonly ManifestEditor $editor,
        private readonly array $inherited,
    ) {}

    /**
     * The only key that isn't a tool's name.
     */
    public function key(): string
    {
        return 'symlinks';
    }

    /**
     * Says files, though a link can be a whole config folder.
     */
    public function label(): string
    {
        return 'Symlinked config files';
    }

    /**
     * ln and mv ship with macOS.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Dotbot configs, Stow's settings and Holman-style *.symlink files.
     */
    public function manifestPatterns(): array
    {
        return ['*.conf.yaml', '.stowrc', '*.symlink'];
    }

    /**
     * Two layouts that claim the same path can't be asked about here, since prompts don't run
     * under the scan's status display. The first one wins and the log says so.
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        // A link fixed earlier in this run would otherwise still read as broken.
        clearstatcache(true);

        $log('Reading the link layout');
        $links = (new LinkMapBuilder($this->repo->root(), $this->home(), $this->configHome()))->build($manifests, $log);
        $log(count($links).' links expected');
        $items = [];

        foreach ($links as $link) {
            $item = $this->driftOf($link, $log);

            if ($item !== null) {
                $items[$item->key()] = $item;
            }
        }

        return new DriftReport($this->key(), array_values($items));
    }

    /**
     * Ignore and Skip come last for every kind.
     */
    public function actionsFor(DriftItem $item): array
    {
        $labels = [];

        foreach ([...self::ACTIONS[$item->kind->value] ?? [], Action::Ignore, Action::Skip] as $action) {
            $labels[$action->value] = $action->label();
        }

        return $labels;
    }

    /**
     * Each step checks the path again before it touches it, since the scan may be minutes old.
     */
    public function steps(Decision $decision): array
    {
        $link = $this->linkOf($decision->item);

        return match ($decision->action) {
            Action::Link => [$this->linkStep($link)],
            Action::BackUpAndLink => [$this->backUpStep($link->target), $this->linkStep($link)],
            Action::Adopt => [$this->backUpStep($link->source), $this->adoptStep($link), $this->linkStep($link)],
            Action::Repoint => [$this->repointStep($link)],
            Action::Unlink => [$this->unlinkStep($link)],
            Action::Ignore, Action::Skip => [],
            default => throw new LogicException("Symlinks can't {$decision->action->value} a link"),
        };
    }

    /**
     * What's in ~ on the left, the repo on the right.
     */
    public function diff(DriftItem $item): string
    {
        $link = $this->linkOf($item);
        $result = $this->shell->run(['/usr/bin/diff', '-ru', $link->target, $link->source], self::DIFF_TIMEOUT);

        return match (true) {
            $result->successful() => 'No differences.',
            $result->exitCode === self::DIFF_FOUND_DIFFERENCES => rtrim($result->output),
            default => 'diff failed: '.trim($result->errorOutput),
        };
    }

    /**
     * @param  callable(string): void  $log
     * @return ?DriftItem null when the link is already right
     */
    private function driftOf(Link $link, callable $log): ?DriftItem
    {
        // LinkMapBuilder only keeps a missing source when there's already a link to it.
        if (! file_exists($link->source)) {
            return is_link($link->target)
                ? $this->item(DriftKind::Broken, $link, $this->relativeToRepo($link->source).' is gone from the repo')
                : null;
        }

        $current = realpath($link->target);

        if ($current !== false && $current === realpath($link->source)) {
            return null;
        }

        $linkedFolder = $this->linkedParentOf($link->target);

        if ($linkedFolder !== null) {
            $item = $this->folderDrift($link, $linkedFolder);

            if ($item === null) {
                $log("Skipped {$link->target}: {$linkedFolder} is a link and the repo doesn't have the same folder");
            }

            return $item;
        }

        if (is_link($link->target)) {
            return $this->item(DriftKind::WrongTarget, $link, 'points at '.readlink($link->target));
        }

        if (file_exists($link->target)) {
            return $this->item(DriftKind::Conflict, $link, 'a real '.(is_dir($link->target) ? 'folder' : 'file').' is in the way');
        }

        return $this->item(DriftKind::NotLinked, $link, 'should point at '.$this->relativeToRepo($link->source));
    }

    /**
     * The file sits in a linked folder that points somewhere else, so the folder link is what
     * needs fixing.
     *
     * @return ?DriftItem null when the repo keeps the file under a different folder name
     */
    private function folderDrift(Link $link, string $folder): ?DriftItem
    {
        $inside = substr($link->target, strlen($folder));

        if (! str_ends_with($link->source, $inside)) {
            return null;
        }

        $folderLink = new Link(substr($link->source, 0, -strlen($inside)), $folder);

        return $this->item(DriftKind::WrongTarget, $folderLink, 'points at '.readlink($folder));
    }

    /**
     * @return ?string the nearest folder above $path, below ~, that is a symlink
     */
    private function linkedParentOf(string $path): ?string
    {
        $home = $this->home();

        for ($dir = dirname($path); str_starts_with($dir, "{$home}/"); $dir = dirname($dir)) {
            if (is_link($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Stamps the item with this check's key.
     *
     * @param  string  $detail  the short reason the report shows, such as "a real file is in the way"
     */
    private function item(DriftKind $kind, Link $link, string $detail): DriftItem
    {
        return new DriftItem($this->key(), $kind, $link, $detail);
    }

    /**
     * `ln -s`, creating the folders above the link.
     */
    private function linkStep(Link $link): Step
    {
        return new Step(Step::commandLine(['ln', '-s', $link->source, $link->target]), function () use ($link) {
            if (file_exists($link->target) || is_link($link->target)) {
                throw new RuntimeException("{$link->target} already exists");
            }

            AtomicFile::makeParentOf($link->target);

            if (! @symlink($link->source, $link->target)) {
                throw new RuntimeException("Could not link {$link->target} to {$link->source}");
            }

            return ['kind' => 'link', 'target' => $link->target, 'source' => $link->source];
        });
    }

    /**
     * Moves the file or folder into this run's backup folder.
     */
    private function backUpStep(string $path): Step
    {
        return new Step(
            Step::commandLine(['mv', $path, $this->editor->backupOf($path)]),
            fn () => ['kind' => 'move', 'from' => $path, 'to' => $this->editor->moveToBackups($path)],
        );
    }

    /**
     * Moves what's in ~ into the repo, where the repo's copy was. The repo's copy is backed up
     * first.
     */
    private function adoptStep(Link $link): Step
    {
        return new Step(Step::commandLine(['mv', $link->target, $link->source]), function () use ($link) {
            if (file_exists($link->source) || is_link($link->source)) {
                throw new RuntimeException("{$link->source} is still in the repo");
            }

            if (! @rename($link->target, $link->source)) {
                throw new RuntimeException("Could not move {$link->target} to {$link->source}");
            }

            $this->editor->markChanged($link->source);

            return ['kind' => 'move', 'from' => $link->target, 'to' => $link->source];
        });
    }

    /**
     * Swaps the link in one rename, so the path never goes missing. The old destination isn't
     * touched.
     */
    private function repointStep(Link $link): Step
    {
        return new Step(Step::commandLine(['ln', '-sfn', $link->source, $link->target]), function () use ($link) {
            if (! is_link($link->target)) {
                throw new RuntimeException("{$link->target} isn't a symlink anymore");
            }

            $pointedTo = readlink($link->target);
            $temporary = dirname($link->target).'/.dotsnitch-'.bin2hex(random_bytes(4));

            if (! @symlink($link->source, $temporary)) {
                throw new RuntimeException("Could not create a link next to {$link->target}");
            }

            if (! @rename($temporary, $link->target)) {
                unlink($temporary);

                throw new RuntimeException("Could not repoint {$link->target}");
            }

            return ['kind' => 'repoint', 'target' => $link->target, 'source' => $link->source, 'pointed_to' => $pointedTo];
        });
    }

    /**
     * Removes only a symlink, never a real file.
     */
    private function unlinkStep(Link $link): Step
    {
        return new Step(Step::commandLine(['rm', $link->target]), function () use ($link) {
            if (! is_link($link->target)) {
                throw new RuntimeException("{$link->target} isn't a symlink anymore");
            }

            $pointedTo = readlink($link->target);

            if (! @unlink($link->target)) {
                throw new RuntimeException("Could not remove {$link->target}");
            }

            return ['kind' => 'unlink', 'target' => $link->target, 'pointed_to' => $pointedTo];
        });
    }

    /**
     * @throws LogicException for an item another check reported
     */
    private function linkOf(DriftItem $item): Link
    {
        return $item->subject instanceof Link
            ? $item->subject
            : throw new LogicException("Symlinks got a drift item that isn't a link: {$item->key()}");
    }

    /**
     * Such as ".config/nvim", for details.
     */
    private function relativeToRepo(string $path): string
    {
        $root = $this->repo->root();

        return str_starts_with($path, "{$root}/") ? substr($path, strlen($root) + 1) : $path;
    }

    /**
     * @throws RuntimeException when HOME is unset or empty
     */
    private function home(): string
    {
        $home = $this->inherited['HOME'] ?? '';

        return $home !== '' ? rtrim($home, '/') : throw new RuntimeException('HOME is not set');
    }

    /**
     * XDG_CONFIG_HOME, or ~/.config.
     */
    private function configHome(): string
    {
        $configHome = $this->inherited['XDG_CONFIG_HOME'] ?? '';

        return $configHome !== '' ? rtrim($configHome, '/') : $this->home().'/.config';
    }
}
