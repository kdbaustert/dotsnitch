<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftReport;
use App\Sync\Step;
use RuntimeException;

/**
 * One check, such as Homebrew or symlinks. The command only ever talks to this interface.
 */
interface SyncTarget
{
    /**
     * @return string such as "brew"; used in settings keys and drift item keys, so never change one
     */
    public function key(): string;

    /**
     * @return string such as "Homebrew", for prompts and tables
     */
    public function label(): string;

    /**
     * False when the tool isn't installed on this Mac, so the check is left out.
     */
    public function isAvailable(): bool;

    /**
     * A plain name also matches suffixed copies, such as Brewfile.work or pipx.txt.kennys-mbp.
     * "parent/name" matches only a file of that name inside a folder called parent.
     *
     * @return list<string> file names, globs or "parent/name"
     */
    public function manifestPatterns(): array;

    /**
     * @param  list<string>  $manifests  absolute paths, base file first, then overlays
     * @param  callable(string): void  $log  takes one status line for the scanning display
     */
    public function scan(array $manifests, callable $log): DriftReport;

    /**
     * What the user can do about an item from the last scan(), suggestion first and Skip last.
     *
     * @return array<string, string> Action value => menu label, such as 'replace' => 'Replace with yt-dlp'
     */
    public function actionsFor(DriftItem $item): array;

    /**
     * The commands and file edits that carry out a decision, in the order they must run. The
     * caller stops at the first step that fails. Ignore and Skip have none; the command handles
     * those.
     *
     * @return list<Step>
     *
     * @throws RuntimeException when the decision can't be carried out, such as removing an entry
     *                          that no manifest lists in a line it can edit
     */
    public function steps(Decision $decision): array;
}
