<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestEdit;
use App\Data\Package;

/**
 * A manifest that's edited a line at a time. Subclasses say which lines list a package.
 */
abstract class LineBasedEdit implements ManifestEdit
{
    /** @var list<string> */
    protected array $lines;

    /** \r\n for a file saved on Windows, so a new line ends like the ones around it. */
    private readonly string $lineEnding;

    private readonly bool $endsWithNewline;

    /**
     * @param  string  $contents  the whole file, empty for a new one
     */
    public function __construct(string $contents)
    {
        $this->lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $this->endsWithNewline = $contents === '' || str_ends_with($contents, $this->lineEnding);
        $this->lines = $contents === '' ? [] : explode(
            $this->lineEnding,
            $this->endsWithNewline ? substr($contents, 0, -strlen($this->lineEnding)) : $contents,
        );
    }

    /**
     * @return list<int> indexes of the lines that list the package and can be edited
     */
    abstract protected function linesOf(Package $package): array;

    /**
     * Counts only the lines linesOf() returns, the ones remove() would drop.
     */
    public function lists(Package $package): bool
    {
        return $this->linesOf($package) !== [];
    }

    /**
     * Drops every line that lists it, so a duplicate goes too.
     */
    public function remove(Package $package): bool
    {
        $found = $this->linesOf($package);

        foreach ($found as $index) {
            unset($this->lines[$index]);
        }

        $this->lines = array_values($this->lines);

        return $found !== [];
    }

    /**
     * Keeps the original's trailing newline, and gives a new file one.
     */
    public function contents(): string
    {
        $contents = implode($this->lineEnding, $this->lines);

        return $this->endsWithNewline && $this->lines !== [] ? $contents.$this->lineEnding : $contents;
    }
}
