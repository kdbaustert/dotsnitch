<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestEdit;
use App\Contracts\PackageSpec;
use App\Data\Package;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Line edits on a manifest that lists packages by name. Only a line holding a single entry, with
 * optional quotes and an optional comment after it, gets edited. A line with two names is still
 * read, but left to the user to change.
 */
abstract class EntryLinesEdit implements ManifestEdit
{
    /** Indent, opening quote, the entry, then a comment or nothing. A quoted entry can hold spaces. */
    private const ENTRY_LINE = '/^(\s*)(?|(["\'])([^"\'#]+)\2|()([^\s"\'#]+))(\s+#.*|\s*)$/';

    /** A quoted or bare word, once comments are gone. */
    private const WORD = '/"([^"]*)"|\'([^\']*)\'|([^\s"\']+)/';

    /** @var list<string> */
    protected array $lines;

    private readonly bool $endsWithNewline;

    /**
     * @param  string  $contents  the whole file, empty for a new one
     */
    public function __construct(
        string $contents,
        private readonly PackageSpec $spec,
    ) {
        $this->endsWithNewline = $contents === '' || str_ends_with($contents, "\n");
        $this->lines = $contents === '' ? [] : explode("\n", $this->endsWithNewline ? substr($contents, 0, -1) : $contents);
    }

    /**
     * Every package the file lists, in file order, including ones on lines edits leave alone.
     *
     * @return Collection<int, Package>
     */
    abstract public function entries(): Collection;

    /**
     * @return list<int> indexes of the lines an entry may sit on
     */
    abstract protected function entryLineIndexes(): array;

    /**
     * @throws RuntimeException when the file has nowhere to put a new entry
     */
    abstract protected function insertionPoint(): int;

    /**
     * Writes the spec's entry, indented like the entry above it, and in double quotes when it has
     * a space, like "1436522307:Transmit 5".
     *
     * @throws RuntimeException when the file has nowhere to put a new entry
     */
    public function add(Package $package): void
    {
        if ($this->linesOf($package) !== []) {
            return;
        }

        $entry = $this->spec->entry($package);
        $at = $this->insertionPoint();
        array_splice($this->lines, $at, 0, [$this->indentAbove($at).(preg_match('/\s/', $entry) ? "\"{$entry}\"" : $entry)]);
    }

    /**
     * Only a line holding just that entry goes. A line with two names is left for you to edit.
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
     * Keeps the old line's indent, quotes and comment. The old pin is dropped.
     */
    public function replace(Package $old, Package $new): bool
    {
        if ($this->linesOf($new) !== []) {
            return $this->remove($old);
        }

        $found = $this->linesOf($old);

        foreach ($found as $index) {
            $this->lines[$index] = preg_replace_callback(
                self::ENTRY_LINE,
                fn (array $match) => $match[1].$match[2].$new->name.$match[2].$match[4],
                $this->lines[$index],
            );
        }

        return $found !== [];
    }

    /**
     * Keeps the original's trailing newline, and gives a new file one.
     */
    public function contents(): string
    {
        $contents = implode("\n", $this->lines);

        return $this->endsWithNewline && $this->lines !== [] ? "{$contents}\n" : $contents;
    }

    /**
     * Skips words holding a $, since those are shell variables rather than names.
     *
     * @return list<Package>
     */
    protected function packagesIn(string $text): array
    {
        preg_match_all(self::WORD, $this->withoutComment($text), $words, PREG_SET_ORDER);
        $packages = [];

        foreach ($words as $word) {
            // Only one of the three groups matched, so joining them gives the word unquoted.
            $entry = implode('', array_slice($word, 1));
            $package = str_contains($entry, '$') ? null : $this->spec->parse($entry);

            if ($package !== null) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * A # only starts a comment at the start of a line or after a space, as in a shell script.
     */
    protected function withoutComment(string $text): string
    {
        return (string) preg_replace('/(^|\s)#.*$/', '', $text);
    }

    /**
     * Matches by name, so `typescript@6` is found when removing typescript.
     *
     * @return list<int>
     */
    private function linesOf(Package $package): array
    {
        $found = [];

        foreach ($this->entryLineIndexes() as $index) {
            if (preg_match(self::ENTRY_LINE, $this->lines[$index], $match) && $this->spec->parse($match[3])?->name === $package->name) {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * The indent of the nearest entry above $index, or none.
     */
    private function indentAbove(int $index): string
    {
        $above = array_reverse(array_filter($this->entryLineIndexes(), fn (int $line) => $line < $index));

        foreach ($above as $line) {
            if (preg_match(self::ENTRY_LINE, $this->lines[$line], $match)) {
                return $match[1];
            }
        }

        return '';
    }
}
