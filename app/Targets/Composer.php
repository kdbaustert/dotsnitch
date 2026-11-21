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
     * The resolver finds composer on your login PATH.
     */
    public function __construct(
        Shell $shell,
        private readonly PathResolver $paths,
        ManifestEditor $editor,
    ) {
        parent::__construct($shell, $editor);
        $this->spec = new ComposerSpec;
    }

    /**
     * Used in --checks and under ignore.composer in config.json.
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
     * True when composer is on the resolved PATH.
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
        $show = $this->json([$this->composer(), 'global', 'show', '--direct', '--format=json']);

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
        return [$this->composer(), 'global', 'require', $this->spec->argument($package)];
    }

    /**
     * By name only, since a pin doesn't matter for a removal.
     */
    protected function uninstallCommand(Package $package): array
    {
        return [$this->composer(), 'global', 'remove', $package->name];
    }

    /**
     * @throws RuntimeException when composer isn't on PATH
     */
    private function composer(): string
    {
        return $this->paths->find('composer') ?? throw new RuntimeException('Composer is not installed');
    }
}
