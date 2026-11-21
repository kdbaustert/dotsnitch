<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Shell;
use RuntimeException;

/**
 * Posts a macOS notification. terminal-notifier is used when it's installed, since clicking its
 * notification can open dotsnitch in Terminal. osascript ships with macOS, so it's the fallback.
 */
final class Notifier
{
    private const TITLE = 'dotsnitch';

    public function __construct(
        private readonly Shell $shell,
        private readonly PathResolver $paths,
    ) {}

    /**
     * @throws RuntimeException when the notification couldn't be posted
     */
    public function notify(string $message): void
    {
        $result = $this->shell->run($this->commandFor($message), 30);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) ?: "exit code {$result->exitCode}");
        }
    }

    /**
     * @return list<string>
     */
    private function commandFor(string $message): array
    {
        $notifier = $this->paths->find('terminal-notifier');

        if ($notifier === null) {
            // Passing the message as an argument keeps quotes in it out of the AppleScript.
            return ['/usr/bin/osascript', '-e', 'on run argv', '-e', 'display notification (item 1 of argv) with title "'.self::TITLE.'"', '-e', 'end run', $message];
        }

        $command = [$notifier, '-title', self::TITLE, '-message', $message, '-group', LaunchAgent::LABEL];
        $self = SelfPath::command();

        // Terminal can open a single executable, but not PHP plus a script.
        if (count($self) === 1) {
            array_push($command, '-execute', 'open -a Terminal '.escapeshellarg($self[0]));
        }

        return $command;
    }
}
