<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Shell;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The launchd agent in ~/Library/LaunchAgents that runs `status --notify` once a day.
 */
final class LaunchAgent
{
    public const LABEL = 'com.dotsnitch.status';

    private const LAUNCHCTL = '/bin/launchctl';

    /** Variables the agent copies from the terminal that installs it. */
    private const INHERITED = ['SHELL', 'PATH'];

    /** How long install() waits for launchd to finish unloading the old agent. */
    private const UNLOAD_WAIT_SECONDS = 5;

    private const UNLOAD_POLL_MICROSECONDS = 100_000;

    /** Past this the log moves to schedule.log.1, so the two stay under 2 MB. */
    private const MAX_LOG_BYTES = 1_000_000;

    /** Enough of the end of the log for the few lines the status view shows. */
    private const TAIL_BYTES = 16_384;

    /**
     * @param  string  $home  the HOME the agent gets, and where Library/LaunchAgents is
     * @param  string  $logPath  where launchd writes the run's output and errors
     * @param  array<string, string>  $environment  the installing terminal's, such as getenv(). The
     *                                              agent gets its SHELL, so the run reads the same login
     *                                              environment, and its PATH for when that read fails.
     */
    public function __construct(
        private readonly Shell $shell,
        private readonly string $home,
        private readonly string $logPath,
        private readonly array $environment = [],
    ) {}

    /**
     * Writes the plist and loads it, unloading the old one first, so installing again just
     * changes the time or picks up a moved binary.
     *
     * @param  string  $at  24-hour HH:MM
     * @param  string  $repo  passed as DOTSNITCH_REPO, since launchd starts with no saved shell state
     *
     * @throws InvalidArgumentException when $at isn't a time
     * @throws RuntimeException when the plist can't be written or launchctl refuses it
     */
    public function install(string $at, string $repo): void
    {
        [$hour, $minute] = self::parseTime($at);
        AtomicFile::makeParentOf($this->logPath);

        AtomicFile::write($this->plistPath(), $this->plist([...SelfPath::command(), 'status', '--notify', '--no-interaction'], [
            'Hour' => $hour,
            'Minute' => $minute,
        ], [
            'HOME' => $this->home,
            ...array_intersect_key($this->environment, array_flip(self::INHERITED)),
            'DOTSNITCH_REPO' => $repo,
        ]));

        // Fails when nothing is loaded yet, which is fine.
        $this->shell->run([self::LAUNCHCTL, 'bootout', $this->service()]);
        $this->waitUntilUnloaded();
        $result = $this->shell->run([self::LAUNCHCTL, 'bootstrap', $this->domain(), $this->plistPath()]);

        if (! $result->successful()) {
            throw new RuntimeException('launchctl bootstrap failed: '.(trim($result->errorOutput) ?: "exit code {$result->exitCode}"));
        }
    }

    /**
     * Unloads the agent and deletes the plist.
     *
     * @return bool false when there was no plist to remove
     */
    public function uninstall(): bool
    {
        $this->shell->run([self::LAUNCHCTL, 'bootout', $this->service()]);

        return is_file($this->plistPath()) && unlink($this->plistPath());
    }

    /**
     * What the plist on disk runs and when, read with plutil since the static build has no XML
     * extension.
     *
     * @return ?array{arguments: list<string>, hour: int, minute: int} null when there's no plist
     *
     * @throws RuntimeException when plutil can't read it
     */
    public function installed(): ?array
    {
        if (! is_file($this->plistPath())) {
            return null;
        }

        $result = $this->shell->run(['/usr/bin/plutil', '-convert', 'json', '-o', '-', $this->plistPath()]);

        try {
            $plist = $result->successful() ? json_decode($result->output, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $plist = null;
        }

        if (! is_array($plist)) {
            throw new RuntimeException("Couldn't read {$this->plistPath()}: ".trim($result->errorOutput));
        }

        return [
            'arguments' => array_values(array_filter($plist['ProgramArguments'] ?? [], is_string(...))),
            'hour' => (int) ($plist['StartCalendarInterval']['Hour'] ?? 0),
            'minute' => (int) ($plist['StartCalendarInterval']['Minute'] ?? 0),
        ];
    }

    /**
     * @return ?string launchd's last exit code, such as "0" or "(never exited)"; null when the
     *                 agent isn't loaded
     */
    public function lastExit(): ?string
    {
        $result = $this->shell->run([self::LAUNCHCTL, 'print', $this->service()]);

        if (! $result->successful()) {
            return null;
        }

        return preg_match('/^\s*last exit code = (.+)$/m', $result->output, $match) === 1 ? trim($match[1]) : 'unknown';
    }

    /**
     * @return list<string> the last $count lines the scheduled runs wrote, oldest first, reaching
     *                      into schedule.log.1 just after a rotation
     */
    public function logTail(int $count): array
    {
        $lines = self::lastLines($this->logPath, $count);
        $missing = $count - count($lines);

        return $missing > 0 ? [...self::lastLines($this->rotatedLogPath(), $missing), ...$lines] : $lines;
    }

    /**
     * Moves the log to schedule.log.1 once it passes MAX_LOG_BYTES, replacing the older one. The
     * run doing it keeps writing to the moved file, since launchd already opened it, and the
     * next run starts a new log.
     */
    public function rotateLog(): void
    {
        if (is_file($this->logPath) && filesize($this->logPath) > self::MAX_LOG_BYTES) {
            rename($this->logPath, $this->rotatedLogPath());
        }
    }

    /**
     * ~/Library/LaunchAgents/<label>.plist, where launchd looks for it at login.
     */
    public function plistPath(): string
    {
        return "{$this->home}/Library/LaunchAgents/".self::LABEL.'.plist';
    }

    /**
     * The current log, not the rotated one.
     */
    public function logPath(): string
    {
        return $this->logPath;
    }

    /**
     * @return array{int, int} hour and minute
     *
     * @throws InvalidArgumentException when $at isn't a 24-hour HH:MM
     */
    public static function parseTime(string $at): array
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $at, $match) !== 1) {
            throw new InvalidArgumentException("--at takes a 24-hour time such as 09:00, not \"{$at}\".");
        }

        return [(int) $match[1], (int) $match[2]];
    }

    /**
     * The plist by hand, since the static build has no XML extension.
     *
     * @param  list<string>  $arguments
     * @param  array<string, int>  $interval  StartCalendarInterval keys
     * @param  array<string, string>  $environment
     */
    private function plist(array $arguments, array $interval, array $environment): string
    {
        $string = fn (string $value) => '<string>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'</string>';
        $keys = fn (array $values, callable $value) => implode('', array_map(
            fn (string $key) => "\t\t<key>{$key}</key>\n\t\t".$value($values[$key])."\n",
            array_keys($values),
        ));

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
            \t<key>Label</key>
            \t{$string(self::LABEL)}
            \t<key>ProgramArguments</key>
            \t<array>
            {$this->indented(array_map($string, $arguments))}\t</array>
            \t<key>StartCalendarInterval</key>
            \t<dict>
            {$keys($interval, fn (int $value) => "<integer>{$value}</integer>")}\t</dict>
            \t<key>StandardOutPath</key>
            \t{$string($this->logPath)}
            \t<key>StandardErrorPath</key>
            \t{$string($this->logPath)}
            \t<key>EnvironmentVariables</key>
            \t<dict>
            {$keys($environment, $string)}\t</dict>
            </dict>
            </plist>

            XML;
    }

    /**
     * bootout can return before launchd lets go of the label, and a bootstrap then fails with
     * "5: Input/output error". Gives up after UNLOAD_WAIT_SECONDS and lets bootstrap say why.
     */
    private function waitUntilUnloaded(): void
    {
        $giveUpAt = microtime(true) + self::UNLOAD_WAIT_SECONDS;

        while ($this->shell->run([self::LAUNCHCTL, 'print', $this->service()])->successful() && microtime(true) < $giveUpAt) {
            usleep(self::UNLOAD_POLL_MICROSECONDS);
        }
    }

    /**
     * Reads only the end of the file, without blank lines.
     *
     * @return list<string> empty when the file doesn't exist
     */
    private static function lastLines(string $path, int $count): array
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false || $count <= 0) {
            return [];
        }

        $start = max((int) filesize($path) - self::TAIL_BYTES, 0);
        fseek($handle, $start);
        $lines = explode("\n", (string) stream_get_contents($handle));
        fclose($handle);

        // Starting mid-file cuts the first line short.
        if ($start > 0) {
            array_shift($lines);
        }

        return array_slice(array_values(array_filter($lines, fn (string $line) => $line !== '')), -$count);
    }

    /**
     * Where rotateLog() moves a full log. Only one old log is kept.
     */
    private function rotatedLogPath(): string
    {
        return "{$this->logPath}.1";
    }

    /**
     * @param  list<string>  $lines
     */
    private function indented(array $lines): string
    {
        return implode('', array_map(fn (string $line) => "\t\t{$line}\n", $lines));
    }

    /**
     * The logged-in user's GUI session, where per-user agents live.
     */
    private function domain(): string
    {
        return 'gui/'.posix_getuid();
    }

    /**
     * The agent's name as launchctl bootout and print want it, such as gui/501/<label>.
     */
    private function service(): string
    {
        return $this->domain().'/'.self::LABEL;
    }
}
