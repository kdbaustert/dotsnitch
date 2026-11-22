<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Contracts\SharesManifest;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\BrewfileLineFormat;
use App\Manifests\LineListFormat;
use App\Manifests\VscodeSpec;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * VS Code extensions, from the code command. Built-in extensions aren't listed by it, so they
 * never show up.
 */
final class Vscode extends PackageManagerTarget implements SharesManifest
{
    private readonly VscodeSpec $spec;

    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new VscodeSpec;
    }

    public function key(): string
    {
        return 'vscode';
    }

    public function label(): string
    {
        return 'VS Code extensions';
    }

    /**
     * False until "Install 'code' command in PATH" has been run in VS Code, or Homebrew's cask
     * linked it.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('code') !== null;
    }

    /**
     * A list of ids, one per line, or the vscode lines of a Brewfile.
     */
    public function manifestPatterns(): array
    {
        return ['vscode-extensions.txt', 'Brewfile', '.Brewfile'];
    }

    public function hasEntriesIn(string $path): bool
    {
        return ! BrewfileLineFormat::isBrewfile($path) || $this->format($path)->read($path)->isNotEmpty();
    }

    protected function format(string $manifest): ManifestFormat
    {
        return BrewfileLineFormat::isBrewfile($manifest) ? new BrewfileLineFormat('extension') : new LineListFormat($this->spec);
    }

    /**
     * @throws RuntimeException when code fails
     */
    protected function installed(): Collection
    {
        return collect($this->lines([$this->binary('code'), '--list-extensions', '--show-versions']))
            ->map(fn (string $line) => $this->spec->parse(trim($line)))
            ->filter()
            ->values();
    }

    /**
     * Keeps the manifest's pin, as publisher.name@version.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('code'), '--install-extension', $this->spec->argument($package)];
    }

    /**
     * By id only.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('code'), '--uninstall-extension', $package->name];
    }
}
