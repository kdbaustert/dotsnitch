<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ManifestFormat;
use App\Contracts\Shell;
use App\Data\Package;
use App\Manifests\ComposerSpec;
use App\Manifests\JsonRequireFormat;
use App\Manifests\LineListFormat;
use App\Support\ManifestEditor;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Global Composer packages. Only direct requires count, so their dependencies never show as
 * untracked. The installed side always comes from `composer global show`, which keeps the check
 * right when the manifest is Composer's own composer.json linked in from dotfiles.
 */
final class Composer extends PackageManagerTarget
{
    private readonly ComposerSpec $spec;

    /**
     * One ComposerSpec serves both kinds of manifest, the composer.json and the plain list.
     */
    public function __construct(Shell $shell, PathResolver $paths, ManifestEditor $editor)
    {
        parent::__construct($shell, $paths, $editor);
        $this->spec = new ComposerSpec;
    }

    /**
     * composer on its own. "global" is left to the label.
     */
    public function key(): string
    {
        return 'composer';
    }

    /**
     * Says global, since a project's own packages aren't checked.
     */
    public function label(): string
    {
        return 'Composer (global)';
    }

    /**
     * False until composer itself is installed, even if dotfiles already linked its composer.json
     * into place.
     */
    public function isAvailable(): bool
    {
        return $this->paths->find('composer') !== null;
    }

    /**
     * Composer's own global composer.json counts too, when dotfiles links it in.
     */
    public function manifestPatterns(): array
    {
        return ['composer/composer.json', '.composer/composer.json', 'composer-global.json', 'Composerfile'];
    }

    /**
     * A .json reads its "require" section; anything else is a list of names.
     */
    protected function format(string $manifest): ManifestFormat
    {
        return $this->hasExtension($manifest, 'json')
            ? new JsonRequireFormat('require', $this->spec)
            : new LineListFormat($this->spec);
    }

    /**
     * @throws RuntimeException when composer prints no JSON
     */
    protected function installed(): Collection
    {
        $show = $this->json([$this->binary('composer'), 'global', 'show', '--direct', '--format=json']);

        // With nothing installed Composer prints [] instead of {"installed": []}.
        return collect($show['installed'] ?? [])
            ->map(fn (mixed $package) => is_string($package['name'] ?? null)
                ? new Package($package['name'], 'package', $package['version'] ?? null)
                : throw new RuntimeException('composer global show listed a package with no name'));
    }

    /**
     * Keeps the manifest's constraint, so laravel/installer:^5 installs a 5.x.
     */
    protected function installCommand(Package $package): array
    {
        return [$this->binary('composer'), 'global', 'require', $this->spec->argument($package)];
    }

    /**
     * By name only, since a pin doesn't matter for a removal.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->binary('composer'), 'global', 'remove', $package->name];
    }
}
