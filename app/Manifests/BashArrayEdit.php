<?php

declare(strict_types=1);

namespace App\Manifests;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The bash arrays in a setup script, such as `pkgs=(` ... `)` ahead of `pnpm add -g "${pkgs[@]}"`.
 * Only arrays the script expands count, so a helper like dirs=(src lib) isn't read as packages;
 * a script that expands none has all of its arrays read. Edits only touch the lines between the
 * parens, one item per line. Items on the line with either paren are read but left alone.
 */
final class BashArrayEdit extends EntryLinesEdit
{
    /** `name=(` or `name+=(`, after an optional keyword like declare -a; the name, then the rest of the line. */
    private const OPENING = '/^\s*(?:[a-z]+\s+(?:-[a-zA-Z]+\s+)?)?([A-Za-z_]\w*)\+?=\((.*)$/';

    /** `${name[@]}` or `${name[*]}`, the way a script hands an array to a command or a loop. */
    private const EXPANSION = '/\$\{([A-Za-z_]\w*)\[[@*]\]\}/';

    /**
     * True when the file has at least one array, even an empty one.
     */
    public function hasArray(): bool
    {
        return $this->arrays() !== [];
    }

    /**
     * Items from every array that counts, including ones on a paren's line, in file order.
     */
    public function entries(): Collection
    {
        $packages = collect();

        foreach ($this->arrays() as $array) {
            $packages->push(...$this->packagesIn($array['inline']));

            foreach ($this->bodyOf($array) as $index) {
                $packages->push(...$this->packagesIn($this->lines[$index]));
            }

            $packages->push(...$this->packagesIn($array['tail']));
        }

        return $packages;
    }

    /**
     * Only lines between the parens. Items on a paren's line are read but never edited.
     */
    protected function entryLineIndexes(): array
    {
        return array_merge(...array_map($this->bodyOf(...), $this->arrays()));
    }

    /**
     * Just above the closing paren of the first array that spans lines.
     *
     * @throws RuntimeException when no array spans lines
     */
    protected function insertionPoint(): int
    {
        foreach ($this->arrays() as $array) {
            if ($array['close'] > $array['open']) {
                return $array['close'];
            }
        }

        throw new RuntimeException('There is no multi-line array to add it to. Add it by hand.');
    }

    /**
     * Open and close are line indexes; inline is what sits after the "(" and tail what sits before
     * the ")". An array that never closes is skipped, the same way bash would fail on it.
     *
     * @return list<array{name: string, open: int, close: int, inline: string, tail: string}>
     */
    private function arrays(): array
    {
        $arrays = [];
        $open = null;

        foreach ($this->lines as $index => $line) {
            if ($open !== null) {
                $code = $this->withoutComment($line);
                $closeAt = $this->closingParenIn($code);

                if ($closeAt !== null) {
                    $arrays[] = [...$open, 'close' => $index, 'tail' => substr($code, 0, $closeAt)];
                    $open = null;
                }

                continue;
            }

            if (! preg_match(self::OPENING, $line, $match)) {
                continue;
            }

            $rest = $this->withoutComment($match[2]);
            $closeAt = $this->closingParenIn($rest);

            if ($closeAt === null) {
                $open = ['name' => $match[1], 'open' => $index, 'inline' => $rest];
            } else {
                $arrays[] = ['name' => $match[1], 'open' => $index, 'close' => $index, 'inline' => substr($rest, 0, $closeAt), 'tail' => ''];
            }
        }

        return $this->expandedOnly($arrays);
    }

    /**
     * The arrays the script expands somewhere, or all of them when it expands none.
     *
     * @param  list<array{name: string, open: int, close: int, inline: string, tail: string}>  $arrays
     * @return list<array{name: string, open: int, close: int, inline: string, tail: string}>
     */
    private function expandedOnly(array $arrays): array
    {
        preg_match_all(self::EXPANSION, implode("\n", array_map($this->withoutComment(...), $this->lines)), $matches);
        $expanded = array_values(array_filter($arrays, fn (array $array) => in_array($array['name'], $matches[1], true)));

        return $expanded === [] ? $arrays : $expanded;
    }

    /**
     * Where bash sees the array end: the first ) outside quotes.
     *
     * @param  string  $code  a line with its comment already gone
     */
    private function closingParenIn(string $code): ?int
    {
        $unquoted = (string) preg_replace_callback('/"[^"]*"|\'[^\']*\'/', fn (array $quoted) => str_repeat('_', strlen($quoted[0])), $code);
        $at = strpos($unquoted, ')');

        return $at === false ? null : $at;
    }

    /**
     * @param  array{name: string, open: int, close: int, inline: string, tail: string}  $array
     * @return list<int> the lines between the parens
     */
    private function bodyOf(array $array): array
    {
        return $array['close'] - $array['open'] < 2 ? [] : range($array['open'] + 1, $array['close'] - 1);
    }
}
