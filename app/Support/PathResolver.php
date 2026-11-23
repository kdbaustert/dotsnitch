<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Shell;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Rebuilds the environment a login shell would have, so dotsnitch finds the same tools from a terminal,
 * cron or launchd. Everything is worked out once per run.
 */
final class PathResolver
{
    public const HOMEBREW_PREFIXES = ['/opt/homebrew', '/usr/local'];

    private const LOGIN_SHELL_TIMEOUT = 5;

    private const TOOL_TIMEOUT = 15;

    private const MARKER = '__DOTSNITCH_ENV__';

    /** Per-process variables that would be wrong in a child started from somewhere else. */
    private const DROPPED_VARIABLES = ['PWD', 'OLDPWD', 'SHLVL', '_'];

    /** Path fragments that tell which version manager owns the active node. */
    private const NODE_MANAGERS = [
        '/.nvm/' => 'nvm',
        '/.volta/' => 'Volta',
        '/mise/' => 'mise',
        '/.asdf/' => 'asdf',
        '/fnm/' => 'fnm',
    ];

    /** @var ?array<string, string> */
    private ?array $loginEnvironment = null;

    /** @var ?array<string, string> */
    private ?array $environment = null;

    /** Set once homebrewPrefix() has an answer, since that answer can be null. */
    private bool $prefixResolved = false;

    private ?string $prefix = null;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  Shell  $shell  must not itself depend on this resolver
     * @param  array<string, string>  $inherited  the environment this process started with
     * @param  list<string>  $homebrewPrefixes
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly UserSettings $settings,
        private readonly array $inherited,
        private readonly array $homebrewPrefixes = self::HOMEBREW_PREFIXES,
    ) {}

    /**
     * @return array<string, string> the login shell's environment with Homebrew first on PATH and each
     *                               tool's global bin dir appended
     */
    public function homeEnvironment(): array
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        $environment = $this->loginEnvironment();
        $loginPath = $this->splitPath($environment['PATH'] ?? '');

        try {
            $prefix = $this->homebrewPrefix();
        } catch (AmbiguousHomebrew $e) {
            $prefix = null;
            $this->warnings[] = $e->getMessage().'. Run `dotsnitch config --machine` to choose one.';
        }

        $brewDirs = $prefix === null ? [] : ["{$prefix}/bin", "{$prefix}/sbin"];
        $path = $this->joinPath([...$brewDirs, ...$loginPath]);
        $toolDirs = $this->toolBinDirs([...$environment, 'PATH' => $path]);

        $environment['PATH'] = $this->joinPath([...$brewDirs, ...$loginPath, ...$toolDirs]);

        return $this->environment = $environment;
    }

    /**
     * @return ?string null when Homebrew isn't installed
     *
     * @throws AmbiguousHomebrew when brew isn't on PATH, both prefixes have one, and no choice is saved
     */
    public function homebrewPrefix(): ?string
    {
        if (! $this->prefixResolved) {
            $this->prefix = $this->detectHomebrewPrefix();
            $this->prefixResolved = true;
        }

        return $this->prefix;
    }

    /**
     * Saves the choice for this Mac and rebuilds the environment on next use.
     */
    public function chooseHomebrewPrefix(string $prefix): void
    {
        $this->settings->set('homebrew.prefix', $prefix);
        $this->useHomebrewPrefix($prefix);
    }

    /**
     * Uses this Homebrew for the rest of the run without saving it.
     */
    public function useHomebrewPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
        $this->prefixResolved = true;
        $this->environment = null;
    }

    /**
     * @throws AmbiguousHomebrew when brew isn't on PATH, both prefixes have one, and no choice is saved
     */
    private function detectHomebrewPrefix(): ?string
    {
        $saved = $this->settings->get('homebrew.prefix');

        if (is_string($saved) && $this->hasBrew($saved)) {
            return $saved;
        }

        $brewOnPath = $this->findIn($this->splitPath($this->loginEnvironment()['PATH'] ?? ''), 'brew');

        if ($brewOnPath !== null) {
            try {
                $result = $this->shell->run([$brewOnPath, '--prefix'], self::TOOL_TIMEOUT);

                if ($result->successful()) {
                    return trim($result->output);
                }
            } catch (ProcessTimedOutException) {
                $this->warnings[] = "`{$brewOnPath} --prefix` took over ".self::TOOL_TIMEOUT.'s; looked in the standard prefixes instead.';
            }
        }

        $installed = array_values(array_filter($this->homebrewPrefixes, $this->hasBrew(...)));

        if (count($installed) > 1) {
            throw new AmbiguousHomebrew($installed);
        }

        return $installed[0] ?? null;
    }

    /**
     * @return ?string absolute path, null when the binary isn't on the resolved PATH
     */
    public function find(string $binary): ?string
    {
        return $this->findIn($this->splitPath($this->homeEnvironment()['PATH']), $binary);
    }

    /**
     * True when your login shell has the directory on PATH. The resolved PATH can't answer this,
     * since homeEnvironment() appends every tool's bin dir.
     */
    public function onLoginPath(string $dir): bool
    {
        $trimmed = fn (string $path) => rtrim($path, '/');

        return in_array($trimmed($dir), array_map($trimmed, $this->splitPath($this->loginEnvironment()['PATH'] ?? '')), true);
    }

    /**
     * @return ?string such as "Node 22.11.0 via nvm", null when node isn't installed. A node that
     *                 fails or hangs on --version reads "Node (version unknown)".
     */
    public function nodeDescription(): ?string
    {
        $node = $this->find('node');

        if ($node === null) {
            return null;
        }

        try {
            $result = $this->shell->run([$node, '--version'], self::TOOL_TIMEOUT, $this->homeEnvironment());
            $version = $result->successful() ? ltrim(trim($result->output), 'v') : '(version unknown)';
        } catch (ProcessTimedOutException) {
            $version = '(version unknown)';
        }

        $manager = $this->nodeManager($node);

        return $manager === null ? "Node {$version}" : "Node {$version} via {$manager}";
    }

    /**
     * @return list<string> problems hit while building the environment, for display
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Falls back to the inherited environment when the login shell fails or hangs, so a broken
     * .zshrc degrades the PATH instead of stopping the run.
     *
     * @return array<string, string>
     */
    private function loginEnvironment(): array
    {
        return $this->loginEnvironment ??= $this->readLoginEnvironment();
    }

    /**
     * Runs $SHELL as a login, interactive shell so .zprofile and .zshrc both load, then dumps its env.
     * +m turns job control off. With it on, zsh makes itself the terminal's foreground group and
     * never hands it back, so the next prompt's stty stops dotsnitch with SIGTTOU. fish gets no
     * +m, since it has no such flag.
     *
     * @return array<string, string>
     */
    private function readLoginEnvironment(): array
    {
        $shell = $this->inherited['SHELL'] ?? '/bin/zsh';
        $jobControlOff = basename($shell) === 'fish' ? [] : ['+m'];

        try {
            $result = $this->shell->run(
                [$shell, ...$jobControlOff, '-lic', 'printf '.self::MARKER.'; /usr/bin/env -0'],
                self::LOGIN_SHELL_TIMEOUT,
            );
        } catch (ProcessTimedOutException) {
            $this->warnings[] = "{$shell} took over ".self::LOGIN_SHELL_TIMEOUT.'s to start; using the inherited PATH.';

            return $this->withoutDroppedVariables($this->inherited);
        }

        $markerAt = strrpos($result->output, self::MARKER);

        if (! $result->successful() || $markerAt === false) {
            $this->warnings[] = "Couldn't read the environment from {$shell}; using the inherited PATH.";

            return $this->withoutDroppedVariables($this->inherited);
        }

        // Interactive shells can print banners before the marker, so only what follows it counts.
        $variables = $this->parseNullSeparated(substr($result->output, $markerAt + strlen(self::MARKER)));

        return [...$variables, 'HOME' => $this->inherited['HOME'] ?? $variables['HOME'] ?? ''];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function withoutDroppedVariables(array $variables): array
    {
        return array_diff_key($variables, array_flip(self::DROPPED_VARIABLES));
    }

    /**
     * Reads `env -0` output. Values can hold newlines, which is why it isn't split on them.
     *
     * @return array<string, string> without the DROPPED_VARIABLES
     */
    private function parseNullSeparated(string $output): array
    {
        $variables = [];

        foreach (explode("\0", $output) as $entry) {
            [$name, $value] = array_pad(explode('=', $entry, 2), 2, null);

            if ($name === '' || $value === null || in_array($name, self::DROPPED_VARIABLES, true)) {
                continue;
            }

            $variables[$name] = $value;
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $environment  used to run the tools, which may need node or PHP on PATH
     * @return list<string> existing directories only
     */
    private function toolBinDirs(array $environment): array
    {
        // The inherited fallback has no HOME when the process was started without one.
        $home = $environment['HOME'] ?? '';

        // Asked at once, since composer and npm take a tenth of a second or more each.
        [$composer, $npm, $pnpm, $uv] = SideBySide::run([
            fn () => $this->lastLineOf(['composer', 'global', 'config', 'bin-dir', '--absolute'], $environment),
            fn () => $this->npmBinDir($environment),
            fn () => $this->lastLineOf(['pnpm', 'bin', '-g'], $environment),
            fn () => $this->lastLineOf(['uv', 'tool', 'dir', '--bin'], $environment),
        ]);

        $candidates = [
            $composer,
            $npm,
            $pnpm ?? (isset($environment['PNPM_HOME']) ? $environment['PNPM_HOME'].'/bin' : null),
            "{$home}/.local/bin",
            $uv,
            ($environment['CARGO_HOME'] ?? "{$home}/.cargo").'/bin',
        ];

        return array_values(array_filter($candidates, fn (?string $dir) => $dir !== null && is_dir($dir)));
    }

    /**
     * npm only reports its global prefix, so this adds the /bin itself.
     *
     * @param  array<string, string>  $environment
     */
    private function npmBinDir(array $environment): ?string
    {
        $prefix = $this->lastLineOf(['npm', 'prefix', '-g'], $environment);

        return $prefix === null ? null : "{$prefix}/bin";
    }

    /**
     * Runs a tool found on the environment's PATH and returns the last non-empty line of its output.
     *
     * @param  list<string>  $command  starting with the bare binary name
     * @param  array<string, string>  $environment
     * @return ?string null when the tool is missing, fails or times out
     */
    private function lastLineOf(array $command, array $environment): ?string
    {
        $binary = $this->findIn($this->splitPath($environment['PATH']), $command[0]);

        if ($binary === null) {
            return null;
        }

        $command[0] = $binary;

        try {
            $result = $this->shell->run($command, self::TOOL_TIMEOUT, $environment);
        } catch (ProcessTimedOutException) {
            $this->warnings[] = "`{$command[0]} ".implode(' ', array_slice($command, 1)).'` took over '.self::TOOL_TIMEOUT.'s; skipped its bin dir.';

            return null;
        }

        $lines = array_filter(array_map(trim(...), explode("\n", $result->output)));

        return $result->successful() && $lines !== [] ? end($lines) : null;
    }

    /**
     * @param  string  $node  absolute path to the node binary
     * @return ?string such as "nvm" or "Homebrew", null for any other install
     */
    private function nodeManager(string $node): ?string
    {
        foreach (self::NODE_MANAGERS as $fragment => $manager) {
            if (str_contains($node, $fragment)) {
                return $manager;
            }
        }

        foreach ($this->activeHomebrewPrefixes() as $prefix) {
            if (str_starts_with($node, "{$prefix}/")) {
                return 'Homebrew';
            }
        }

        return null;
    }

    /**
     * Only prefixes that really hold a Homebrew, since the nodejs.org installer also puts node
     * in /usr/local.
     *
     * @return list<string>
     */
    private function activeHomebrewPrefixes(): array
    {
        try {
            $prefix = $this->homebrewPrefix();
        } catch (AmbiguousHomebrew $e) {
            return $e->prefixes;
        }

        return $prefix === null ? [] : [$prefix];
    }

    /**
     * @param  list<string>  $path
     * @return ?string the first executable named $binary, in PATH order
     */
    private function findIn(array $path, string $binary): ?string
    {
        foreach ($path as $dir) {
            $candidate = "{$dir}/{$binary}";

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Looks for bin/brew, because /usr/local exists on every Mac whether Homebrew is there or not.
     */
    private function hasBrew(string $prefix): bool
    {
        return is_executable("{$prefix}/bin/brew");
    }

    /**
     * Drops empty entries, which a shell would read as the current directory.
     *
     * @return list<string>
     */
    private function splitPath(string $path): array
    {
        return array_values(array_filter(explode(':', $path)));
    }

    /**
     * Keeps the first copy of a repeated directory, so earlier entries still win.
     *
     * @param  list<string>  $dirs
     */
    private function joinPath(array $dirs): string
    {
        return implode(':', array_unique($dirs));
    }
}
