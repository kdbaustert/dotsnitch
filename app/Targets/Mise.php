<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\Shell;
use App\Contracts\SyncTarget;
use App\Data\Package;
use App\Support\PathResolver;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\Step;
use LogicException;
use RuntimeException;

/**
 * Tools the dotfiles' mise config asks for that mise hasn't installed.
 *
 * It only goes one way. mise keeps every version a project config once asked for, so a version
 * that's installed and not in the dotfiles isn't drift.
 */
final class Mise implements SyncTarget
{
    /** Node builds from source on some Macs, the same allowance the package managers get. */
    private const INSTALL_TIMEOUT = 1800;

    /** @var array<string, array{version: string, manifest: string}> what the last scan found missing, by tool */
    private array $missing = [];

    public function __construct(
        private readonly Shell $shell,
        private readonly PathResolver $paths,
    ) {}

    public function key(): string
    {
        return 'mise';
    }

    public function label(): string
    {
        return 'mise';
    }

    public function isAvailable(): bool
    {
        return $this->paths->find('mise') !== null;
    }

    /**
     * The global config as it sits in ~/.config, then the two names a config has at a repo's root.
     */
    public function manifestPatterns(): array
    {
        return ['mise/config.toml', 'mise.toml', '.mise.toml'];
    }

    /**
     * Asks mise itself, with each file standing in for the global config, so "22" and "latest"
     * resolve the way they will at install time. That lookup can go to the network.
     *
     * @throws RuntimeException when mise can't read a config
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        $this->missing = [];

        foreach ($manifests as $manifest) {
            $listed = $this->shell->run([$this->mise(), 'ls', '--missing', '--json'], environment: $this->readingFrom($manifest));

            if (! $listed->successful()) {
                throw new RuntimeException(trim($listed->errorOutput) ?: "mise ls exited with code {$listed->exitCode}");
            }

            foreach (json_decode($listed->output, true, flags: JSON_THROW_ON_ERROR) as $tool => $versions) {
                foreach ($versions as $version) {
                    // A mise.toml in the folder dotsnitch was run from is listed too.
                    if (realpath($version['source']['path'] ?? '') === realpath($manifest)) {
                        $this->missing[$tool] = ['version' => $version['version'], 'manifest' => $manifest];
                    }
                }
            }
        }

        $items = [];

        foreach ($this->missing as $tool => $found) {
            $items[] = new DriftItem($this->key(), DriftKind::Missing, new Package((string) $tool, $this->key(), $found['version']));
        }

        return new DriftReport($this->key(), $items);
    }

    public function actionsFor(DriftItem $item): array
    {
        return [
            Action::Install->value => Action::Install->label(),
            Action::Ignore->value => Action::Ignore->label(),
            Action::Skip->value => Action::Skip->label(),
        ];
    }

    public function steps(Decision $decision): array
    {
        return match ($decision->action) {
            Action::Install => [$this->installStep($decision->item->subject->name)],
            Action::Ignore, Action::Skip => [],
            default => throw new LogicException("mise can't {$decision->action->value}"),
        };
    }

    /**
     * Installs the version the scan resolved, so the undo removes exactly that one.
     */
    private function installStep(string $tool): Step
    {
        $found = $this->missing[$tool] ?? throw new RuntimeException("{$tool} wasn't in the last scan");
        $command = [$this->mise(), 'install', "{$tool}@{$found['version']}"];
        $environment = $this->readingFrom($found['manifest']);

        return new Step(Step::commandLine($command), function () use ($command, $environment, $tool, $found) {
            $result = $this->shell->run($command, self::INSTALL_TIMEOUT, $environment);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput) ?: "Exited with code {$result->exitCode}");
            }

            return [
                'kind' => 'command',
                'command' => $command,
                'undo' => [[$this->mise(), 'uninstall', "{$tool}@{$found['version']}"]],
                'environment' => $environment,
            ];
        });
    }

    /**
     * @return array<string, string> the environment that makes mise treat $manifest as its global config
     */
    private function readingFrom(string $manifest): array
    {
        return ['MISE_GLOBAL_CONFIG_FILE' => $manifest];
    }

    private function mise(): string
    {
        return $this->paths->find('mise') ?? 'mise';
    }
}
