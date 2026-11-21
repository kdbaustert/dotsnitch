<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestEdit;
use App\Data\Package;

/**
 * Line edits on a Brewfile. Only plain `tap`, `brew` and `cask` lines are matched, with either
 * quote style and any options or comment after the name. Anything built in Ruby is left to the
 * user.
 */
final class BrewfileEdit implements ManifestEdit
{
    /** Package type to its Brewfile directive, in the order the sections usually run. */
    private const DIRECTIVES = ['tap' => 'tap', 'formula' => 'brew', 'cask' => 'cask'];

    /** A top-level line of any Brewfile directive, such as `mas "Xcode", id: 497799835`. */
    private const ANY_DIRECTIVE = '/^([a-z_]+)\s+["\']/';

    /** @var list<string> */
    private array $lines;

    private readonly bool $endsWithNewline;

    /**
     * @param  string  $contents  the whole Brewfile, empty for a new one
     */
    public function __construct(string $contents)
    {
        $this->endsWithNewline = $contents === '' || str_ends_with($contents, "\n");
        $this->lines = $contents === '' ? [] : explode("\n", $this->endsWithNewline ? substr($contents, 0, -1) : $contents);
    }

    /**
     * Goes after the last entry of the same type, or of the closest section before it. With no
     * earlier section it goes above the first entry, since brew bundle installs in file order.
     */
    public function add(Package $package): void
    {
        if ($this->linesOf($package) !== []) {
            return;
        }

        $line = self::DIRECTIVES[$package->type].' "'.$package->name.'"';
        array_splice($this->lines, $this->insertionPoint($package->type), 0, [$line]);
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
     * Keeps the old line's indent, options and comment.
     */
    public function replace(Package $old, Package $new): bool
    {
        if ($this->linesOf($new) !== []) {
            return $this->remove($old);
        }

        $found = $this->linesOf($old);

        foreach ($found as $index) {
            $this->lines[$index] = preg_replace_callback(
                $this->patternFor($old),
                fn (array $match) => $match[1].self::DIRECTIVES[$new->type].$match[2].$match[3].$new->name.$match[3],
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
     * @return list<int> line indexes, including indented ones inside a Ruby block
     */
    private function linesOf(Package $package): array
    {
        return array_keys(preg_grep($this->patternFor($package), $this->lines));
    }

    /**
     * Captures the indent, the space after the directive, and the quote. Tap names are
     * case-insensitive, so the match is too.
     */
    private function patternFor(Package $package): string
    {
        $flags = $package->type === 'tap' ? 'i' : '';

        return '/^(\s*)'.self::DIRECTIVES[$package->type].'(\s+)(["\'])'.preg_quote($package->name, '/').'\3/'.$flags;
    }

    /**
     * Only top-level lines count, so a new entry never lands inside an `if` block.
     */
    private function insertionPoint(string $type): int
    {
        $order = array_flip(array_keys(self::DIRECTIVES));
        $after = null;
        $firstEntry = null;

        foreach ($this->lines as $index => $line) {
            if (! preg_match(self::ANY_DIRECTIVE, $line, $match)) {
                continue;
            }

            $firstEntry ??= $index;
            $lineType = array_search($match[1], self::DIRECTIVES, true);

            if ($lineType !== false && $order[$lineType] <= $order[$type]) {
                $after = $index + 1;
            }
        }

        return $after ?? $firstEntry ?? count($this->lines);
    }
}
