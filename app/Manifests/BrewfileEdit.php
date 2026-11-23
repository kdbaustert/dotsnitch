<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Data\Package;

/**
 * Line edits on a Brewfile. Only plain `tap`, `brew`, `cask`, `mas`, `vscode`, `cargo`, `uv` and
 * `npm` lines are matched, with either quote style and any options or comment after the name.
 * Anything built in Ruby is left to the user.
 *
 * An app's Package name is its App Store id, so a mas line is matched by its id and never by the
 * name in quotes.
 */
final class BrewfileEdit extends LineBasedEdit
{
    /** Package type to its Brewfile directive. uv and npm share the type "package", so that one comes from the constructor. */
    private const DIRECTIVES = ['tap' => 'tap', 'formula' => 'brew', 'cask' => 'cask', 'app' => 'mas', 'extension' => 'vscode', 'crate' => 'cargo'];

    /** The directives in the order their sections usually run. */
    private const SECTIONS = ['tap', 'brew', 'cask', 'mas', 'vscode', 'cargo', 'uv', 'npm'];

    /** A top-level line of any Brewfile directive, such as `mas "Xcode", id: 497799835`. */
    private const ANY_DIRECTIVE = '/^([a-z_]+)\s+["\']/';

    /**
     * @param  array<string, string>  $titles  app name by id, for a new mas line
     * @param  string  $packageDirective  uv or npm, whichever check's packages are being edited
     */
    public function __construct(
        string $contents,
        private readonly array $titles = [],
        private readonly string $packageDirective = 'npm',
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
            : $this->directiveOf($package).' "'.$package->name.'"';
        array_splice($this->lines, $this->insertionPoint($this->directiveOf($package)), 0, [$line]);
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
                fn (array $match) => $match[1].$this->directiveOf($new).$match[2].$match[3].$new->name.$match[3],
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
     * Captures the indent, the space after the directive, and the quote. Tap names, extension ids
     * and uv tool names are case-insensitive, so the match is too. A uv name also matches with _
     * or . where the package has -, the way pip compares names.
     */
    private function patternFor(Package $package): string
    {
        if ($package->type === 'app') {
            return '/^(\s*)mas(\s+)(["\']).*?\3\s*,\s*id:\s*'.preg_quote($package->name, '/').'\b/';
        }

        $directive = $this->directiveOf($package);
        $name = preg_quote($package->name, '/');

        if ($directive === 'uv') {
            $name = str_replace('\-', '[-_.]+', $name);
        }

        $flags = in_array($directive, ['tap', 'vscode', 'uv'], true) ? 'i' : '';

        return '/^(\s*)'.$directive.'(\s+)(["\'])'.$name.'\3/'.$flags;
    }

    private function directiveOf(Package $package): string
    {
        return self::DIRECTIVES[$package->type] ?? $this->packageDirective;
    }

    /**
     * Only top-level lines count, so a new entry never lands inside an `if` block.
     */
    private function insertionPoint(string $directive): int
    {
        $order = array_flip(self::SECTIONS);
        $after = null;
        $firstEntry = null;

        foreach ($this->lines as $index => $line) {
            if (! preg_match(self::ANY_DIRECTIVE, $line, $match)) {
                continue;
            }

            $firstEntry ??= $index;

            if (isset($order[$match[1]]) && $order[$match[1]] <= $order[$directive]) {
                $after = $index + 1;
            }
        }

        return $after ?? $firstEntry ?? count($this->lines);
    }
}
