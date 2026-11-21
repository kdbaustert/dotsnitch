<?php

declare(strict_types=1);

namespace App\Manifests;

use Illuminate\Support\Collection;

/**
 * A plain list, one package per line, with # comments.
 */
final class LineListEdit extends EntryLinesEdit
{
    /**
     * Every name on every line, in file order. One line can hold more than one.
     */
    public function entries(): Collection
    {
        return collect($this->lines)->flatMap($this->packagesIn(...))->values();
    }

    /**
     * Any line can hold an entry.
     */
    protected function entryLineIndexes(): array
    {
        return array_keys($this->lines);
    }

    /**
     * Right after the last line with anything on it, so trailing blank lines stay at the end.
     */
    protected function insertionPoint(): int
    {
        $filled = array_keys(array_filter($this->lines, fn (string $line) => trim($line) !== ''));

        return $filled === [] ? 0 : end($filled) + 1;
    }
}
