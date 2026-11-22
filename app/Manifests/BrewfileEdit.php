<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Data\Package;

/**
 * Line edits on a Brewfile. Only plain `tap`, `brew`, `cask`, `mas` and `vscode` lines are
 * matched, with either quote style and any options or comment after the name. Anything built in
 * Ruby is left to the user.
 *
 * An app's Package name is its App Store id, so a mas line is matched by its id and never by the
 * name in quotes.
 */
final class BrewfileEdit extends LineBasedEdit
{
    /** Package type to its Brewfile directive, in the order the sections usually run. */
    private const DIRECTIVES = ['tap' => 'tap', 'formula' => 'brew', 'cask' => 'cask', 'app' => 'mas', 'extension' => 'vscode'];

    /** A top-level line of any Brewfile directive, such as `mas "Xcode", id: 497799835`. */
    private const ANY_DIRECTIVE = '/^([a-z_]+)\s+["\']/';

    /**
     * @param  array<string, string>  $titles  app name by id, for a new mas line
     */
    public function __construct(
        string $contents,
        private readonly array $titles = [],
    ) {
        parent::__construct($contents);
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

        // brew bundle installs by the id, so the id stands in for a name nobody knows.
        $line = $package->type === 'app'
            ? 'mas "'.addcslashes($this->titles[$package->name] ?? $package->name, '"\\#').'", id: '.$package->name
            : self::DIRECTIVES[$package->type].' "'.$package->name.'"';
        array_splice($this->lines, $this->insertionPoint($package->type), 0, [$line]);
    }

    /**
     * Keeps the old line's indent, options and comment. Not for a mas line, whose id is one of
     * those options.
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
     * @return list<int> line indexes, including indented ones inside a Ruby block
     */
    protected function linesOf(Package $package): array
    {
        return array_keys(preg_grep($this->patternFor($package), $this->lines));
    }

    /**
     * Captures the indent, the space after the directive, and the quote. Tap names and extension
     * ids are case-insensitive, so the match is too.
     */
    private function patternFor(Package $package): string
    {
        if ($package->type === 'app') {
            return '/^(\s*)mas(\s+)(["\']).*?\3\s*,\s*id:\s*'.preg_quote($package->name, '/').'\b/';
        }

        $flags = in_array($package->type, ['tap', 'extension'], true) ? 'i' : '';

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
