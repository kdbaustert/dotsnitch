<?php

declare(strict_types=1);

namespace App\Manifests;

use App\Contracts\ManifestEdit;
use App\Data\Package;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Edits one section of a JSON manifest. The file is re-encoded, so key order and the indent
 * survive but spacing inside a line may not. Names match case-insensitively, as Composer's do.
 */
final class JsonRequireEdit implements ManifestEdit
{
    private const DEFAULT_INDENT = '    ';

    private stdClass $document;

    /** One level, as the file has it; json_encode always writes four spaces. */
    private readonly string $indent;

    private readonly bool $endsWithNewline;

    /**
     * Decodes to objects so an empty {} stays an object.
     *
     * @throws RuntimeException when the text isn't a JSON object
     */
    public function __construct(
        string $contents,
        private readonly string $section,
    ) {
        $this->document = trim($contents) === '' ? new stdClass : $this->decode($contents);
        $this->indent = preg_match('/^([ \t]+)"/m', $contents, $match) ? $match[1] : self::DEFAULT_INDENT;
        $this->endsWithNewline = trim($contents) === '' || str_ends_with($contents, "\n");
    }

    /**
     * Adds a caret constraint on the installed version, the way `composer require` and
     * `npm install --save` write one.
     *
     * @throws RuntimeException when the section isn't an object
     */
    public function add(Package $package): void
    {
        if ($this->keyOf($package) !== null) {
            return;
        }

        $this->entries()->{$package->name} = $this->constraintFor($package);
    }

    /**
     * Matches the name whatever its case. A missing or malformed section is just false here,
     * where add() throws.
     */
    public function lists(Package $package): bool
    {
        return $this->keyOf($package) !== null;
    }

    /**
     * Matches the name whatever its case.
     */
    public function remove(Package $package): bool
    {
        $key = $this->keyOf($package);

        if ($key === null) {
            return false;
        }

        unset($this->entries()->{$key});

        return true;
    }

    /**
     * Keeps the entry's place. The old constraint belonged to the old package, so the new one
     * gets its own.
     */
    public function replace(Package $old, Package $new): bool
    {
        if ($this->keyOf($new) !== null) {
            return $this->remove($old);
        }

        $key = $this->keyOf($old);

        if ($key === null) {
            return false;
        }

        $swapped = new stdClass;

        foreach (get_object_vars($this->entries()) as $name => $constraint) {
            $swapped->{$name === $key ? $new->name : $name} = $name === $key ? $this->constraintFor($new) : $constraint;
        }

        $this->document->{$this->section} = $swapped;

        return true;
    }

    /**
     * Pretty-printed with the file's own indent, ending in a newline only if the original did.
     */
    public function contents(): string
    {
        $json = json_encode($this->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($this->indent !== self::DEFAULT_INDENT) {
            $json = preg_replace_callback(
                '/^(?: {4})+/m',
                fn (array $match) => str_repeat($this->indent, intdiv(strlen($match[0]), 4)),
                (string) $json,
            );
        }

        return $json.($this->endsWithNewline ? "\n" : '');
    }

    /**
     * @throws RuntimeException when the text isn't a JSON object
     */
    private function decode(string $contents): stdClass
    {
        try {
            $document = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON: {$e->getMessage()}", previous: $e);
        }

        return $document instanceof stdClass ? $document : throw new RuntimeException('Expected a JSON object');
    }

    /**
     * The section, created when it's missing. An empty [] counts as missing, since PHP writes an
     * empty object that way.
     *
     * @throws RuntimeException when the section holds something else
     */
    private function entries(): stdClass
    {
        $entries = $this->document->{$this->section} ?? [];

        if ($entries === []) {
            $entries = $this->document->{$this->section} = new stdClass;
        }

        return $entries instanceof stdClass ? $entries : throw new RuntimeException("\"{$this->section}\" isn't an object");
    }

    /**
     * @return ?string the name as the file spells it, null when it isn't listed
     */
    private function keyOf(Package $package): ?string
    {
        $entries = $this->document->{$this->section} ?? null;

        foreach ($entries instanceof stdClass ? array_keys(get_object_vars($entries)) : [] as $name) {
            if (strcasecmp((string) $name, $package->name) === 0) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Such as ^1.5.0 for v1.5.0. A version that isn't a number, like dev-main, gets * instead.
     */
    private function constraintFor(Package $package): string
    {
        return preg_match('/^v?(\d[\w.-]*)$/', (string) $package->version, $match) ? "^{$match[1]}" : '*';
    }
}
