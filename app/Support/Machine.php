<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Shell;

/**
 * Hardware facts read from sysctl, because php_uname('m') reports x86_64 for a PHP running under Rosetta.
 */
final class Machine
{
    /**
     * @param  Shell  $shell  runs sysctl and scutil; tests pass a FakeShell
     */
    public function __construct(private readonly Shell $shell) {}

    /**
     * True on an M-series Mac, even when this PHP is running under Rosetta.
     */
    public function isAppleSilicon(): bool
    {
        return $this->sysctlIsOn('hw.optional.arm64');
    }

    /**
     * True when this PHP is an x86_64 build being translated by Rosetta.
     */
    public function isTranslated(): bool
    {
        return $this->sysctlIsOn('sysctl.proc_translated');
    }

    /**
     * True when packages may land in the Intel Homebrew because this PHP is an x86_64 build.
     */
    public function runsUnderRosetta(): bool
    {
        return $this->isAppleSilicon() && $this->isTranslated();
    }

    /**
     * @return string such as "Kennys-MacBook-Pro", used to match per-machine manifest files; empty
     *                when the Mac has no name, so no host file matches
     */
    public function hostname(): string
    {
        $result = $this->shell->run(['/usr/sbin/scutil', '--get', 'LocalHostName']);

        return $result->successful() ? trim($result->output) : (gethostname() ?: '');
    }

    /**
     * Intel Macs have no hw.optional.arm64 key at all, so a failed lookup counts as off.
     */
    private function sysctlIsOn(string $key): bool
    {
        $result = $this->shell->run(['/usr/sbin/sysctl', '-n', $key]);

        return $result->successful() && trim($result->output) === '1';
    }
}
