<?php

declare(strict_types=1);

namespace App\Sync;

use App\Support\JsonFile;
use DateTimeImmutable;
use RuntimeException;

/**
 * What one sync changed, saved to syncs/<start time>.json again after every step, so a run that
 * dies halfway still leaves what it got done. A sync that changes nothing leaves no file.
 *
 * A step that ran has an "effect" holding what it takes to put back, by kind:
 *
 *   edit     path, backup (null when the edit created the file), sha256 of what was written
 *   link     target, source
 *   move     from, to
 *   repoint  target, source, pointed_to (where the link went before)
 *   unlink   target, pointed_to
 *   command  command, as argv
 *   ignore   check, key
 *
 * An edit that changed nothing has an empty effect. A step that failed has "failed" with the
 * error instead.
 */
final class SyncLog
{
    private const DIRECTORY = 'syncs';

    private const KEPT = 30;

    /** Set by the first step, and names the file. */
    private ?DateTimeImmutable $startedAt = null;

    /** @var list<array<string, mixed>> */
    private array $steps = [];

    /** @var ?array{repo: string, message: string} */
    private ?array $commit = null;

    /**
     * @param  string  $directory  the state folder, such as ~/.local/state/dotsnitch
     */
    public function __construct(private readonly string $directory) {}

    /**
     * @param  array<string, mixed>  $effect  what the step's run returned
     *
     * @throws RuntimeException when the file can't be written
     */
    public function ran(Decision $decision, Step $step, array $effect): void
    {
        $this->add($decision, $step, ['effect' => $effect]);
    }

    /**
     * @throws RuntimeException when the file can't be written
     */
    public function failed(Decision $decision, Step $step, string $error): void
    {
        $this->add($decision, $step, ['failed' => $error]);
    }

    /**
     * Names the commit the sync made in the dotfiles repo. Only git can take that one back.
     *
     * @throws RuntimeException when the file can't be written
     */
    public function committed(string $repo, string $message): void
    {
        $this->commit = ['repo' => $repo, 'message' => $message];
        $this->save();
    }

    /**
     * @param  array<string, mixed>  $outcome  the effect or the failure
     */
    private function add(Decision $decision, Step $step, array $outcome): void
    {
        $this->steps[] = [
            'item' => $decision->item->key(),
            'action' => $decision->action->value,
            'description' => $step->description,
            ...$outcome,
        ];
        $this->save();
    }

    /**
     * Writes the whole file again, then drops the oldest logs past KEPT.
     */
    private function save(): void
    {
        $this->startedAt ??= new DateTimeImmutable;
        $directory = $this->directory.'/'.self::DIRECTORY;

        JsonFile::write($directory.'/'.$this->startedAt->format('Y-m-d\THis').'.json', [
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'steps' => $this->steps,
            'commit' => $this->commit,
        ]);
        JsonFile::keepNewest($directory, self::KEPT);
    }
}
