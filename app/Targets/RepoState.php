<?php

declare(strict_types=1);

namespace App\Targets;

use App\Contracts\ScansWholeRepo;
use App\Contracts\Shell;
use App\Contracts\SyncTarget;
use App\Data\Package;
use App\Data\ProcessResult;
use App\Support\DotfilesRepo;
use App\Support\PathResolver;
use App\Sync\Action;
use App\Sync\Decision;
use App\Sync\DriftItem;
use App\Sync\DriftKind;
use App\Sync\DriftReport;
use App\Sync\Step;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Checks the dotfiles repo itself: changes nobody committed, commits nobody pushed, and commits
 * on the remote this Mac hasn't pulled. Each is about the branch that's checked out, and reads as
 * worktree:main, local:main or remote:main so one can be ignored without hiding the others.
 */
final class RepoState implements ScansWholeRepo, SyncTarget
{
    /** Seconds. Long enough for a slow connection, short enough that an offline Mac isn't held up. */
    private const REMOTE_TIMEOUT = 15;

    /** Keeps git from waiting on a username or password nobody is there to type. */
    private const NO_PROMPT = ['GIT_TERMINAL_PROMPT' => '0'];

    /** What each kind of drift offers before Skip. Committing is left to you. */
    private const ACTIONS = [
        DriftKind::Unpushed->value => [Action::Push, Action::Ignore],
        DriftKind::Behind->value => [Action::Pull, Action::Ignore],
    ];

    public function __construct(
        private readonly DotfilesRepo $repo,
        private readonly Shell $shell,
        private readonly PathResolver $paths,
    ) {}

    /**
     * Not "git", which would read as a list of git tools.
     */
    public function key(): string
    {
        return 'repo';
    }

    public function label(): string
    {
        return 'Dotfiles repo';
    }

    public function isAvailable(): bool
    {
        return $this->paths->find('git') !== null;
    }

    /**
     * It reads git, not a manifest.
     */
    public function manifestPatterns(): array
    {
        return [];
    }

    /**
     * Asks the remote where the branch is with ls-remote, which changes nothing in the repo. A
     * remote that can't be reached is logged and the rest of the scan stands, so a Mac that's
     * offline doesn't fail the check.
     *
     * @throws RuntimeException when git status fails
     */
    public function scan(array $manifests, callable $log): DriftReport
    {
        if (! $this->git(['rev-parse', '--git-dir'])->successful()) {
            $log("{$this->repo->root()} isn't a git repository");

            return new DriftReport($this->key());
        }

        $branch = trim($this->git(['symbolic-ref', '--short', '-q', 'HEAD'])->output) ?: 'HEAD';
        $items = [];
        $changed = count($this->changedFiles());

        if ($changed > 0) {
            $items[] = $this->item(DriftKind::Uncommitted, new Package($branch, 'worktree'), $changed === 1 ? '1 file' : "{$changed} files");
        }

        [$remote, $remoteRef, $tracking] = $this->upstreamOf($branch);

        if ($remote === '') {
            $log("{$branch} has no upstream, so pushes and pulls weren't checked");

            return new DriftReport($this->key(), $items);
        }

        $unpushed = (int) trim($this->git(['rev-list', '--count', "{$tracking}..HEAD"])->output);

        if ($unpushed > 0) {
            $items[] = $this->item(DriftKind::Unpushed, new Package($branch, 'local'), $unpushed === 1 ? '1 commit' : "{$unpushed} commits");
        }

        if ($this->isBehind($remote, $remoteRef, $log)) {
            $items[] = $this->item(DriftKind::Behind, new Package($branch, 'remote'), "{$tracking} has commits this Mac doesn't");
        }

        return new DriftReport($this->key(), $items);
    }

    public function actionsFor(DriftItem $item): array
    {
        $labels = [];

        foreach ([...self::ACTIONS[$item->kind->value] ?? [], Action::Skip] as $action) {
            $labels[$action->value] = $action->label();
        }

        return $labels;
    }

    /**
     * A pull only fast-forwards, so it stops when the two sides have each moved on.
     */
    public function steps(Decision $decision): array
    {
        return match ($decision->action) {
            Action::Push => [$this->commandStep(['push'])],
            Action::Pull => [$this->commandStep(['pull', '--ff-only'])],
            Action::Ignore, Action::Skip => [],
            default => throw new LogicException("The dotfiles repo can't {$decision->action->value}"),
        };
    }

    /**
     * @return list<string> one line of git status per changed or untracked file
     *
     * @throws RuntimeException when git status fails
     */
    private function changedFiles(): array
    {
        $status = $this->git(['status', '--porcelain']);

        if (! $status->successful()) {
            throw new RuntimeException('git status failed: '.trim($status->errorOutput));
        }

        return array_values(array_filter(explode("\n", $status->output), fn (string $line) => $line !== ''));
    }

    /**
     * @return array{string, string, string} the remote, the branch's ref on it, and the local
     *                                       tracking branch, such as origin, refs/heads/main and
     *                                       origin/main; three empty strings with no upstream
     */
    private function upstreamOf(string $branch): array
    {
        $format = '%(upstream:remotename) %(upstream:remoteref) %(upstream:short)';
        $parts = explode(' ', trim($this->git(['for-each-ref', "--format={$format}", "refs/heads/{$branch}"])->output));

        return count($parts) === 3 ? $parts : ['', '', ''];
    }

    /**
     * True when the remote's branch points at a commit that isn't in this Mac's history.
     *
     * @param  callable(string): void  $log
     */
    private function isBehind(string $remote, string $remoteRef, callable $log): bool
    {
        try {
            $listed = $this->shell->run([...$this->gitCommand(), 'ls-remote', $remote, $remoteRef], self::REMOTE_TIMEOUT, self::NO_PROMPT);
        } catch (ProcessTimedOutException) {
            $log("{$remote} didn't answer in ".self::REMOTE_TIMEOUT.' seconds, so pulls weren\'t checked');

            return false;
        }

        $commit = strtok($listed->output, "\t");

        if (! $listed->successful() || $commit === false) {
            $log("Couldn't read {$remoteRef} from {$remote}, so pulls weren't checked");

            return false;
        }

        // Fails for a commit that isn't here at all, too.
        return ! $this->git(['merge-base', '--is-ancestor', $commit, 'HEAD'])->successful();
    }

    private function item(DriftKind $kind, Package $branch, string $detail): DriftItem
    {
        return new DriftItem($this->key(), $kind, $branch, $detail);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function commandStep(array $arguments): Step
    {
        $command = [...$this->gitCommand(), ...$arguments];

        return new Step(Step::commandLine($command), function () use ($command) {
            $result = $this->shell->run($command, self::REMOTE_TIMEOUT * 4, self::NO_PROMPT);

            if (! $result->successful()) {
                throw new RuntimeException(trim($result->errorOutput) ?: "Exited with code {$result->exitCode}");
            }

            return ['kind' => 'command', 'command' => $command];
        });
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ProcessResult
    {
        return $this->shell->run([...$this->gitCommand(), ...$arguments]);
    }

    /**
     * @return list<string> git, pointed at the dotfiles repo
     */
    private function gitCommand(): array
    {
        return [$this->paths->find('git') ?? 'git', '-C', $this->repo->root()];
    }
}
