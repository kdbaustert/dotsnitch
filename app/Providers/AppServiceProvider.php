<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ScansWholeRepo;
use App\Contracts\SharesManifest;
use App\Contracts\Shell;
use App\Support\DotfilesRepo;
use App\Support\LaunchAgent;
use App\Support\LocalTimezone;
use App\Support\Machine;
use App\Support\ManifestEditor;
use App\Support\Manifests;
use App\Support\PathResolver;
use App\Support\ProcessShell;
use App\Support\SelfUpdate;
use App\Support\StatusLock;
use App\Support\UserSettings;
use App\Sync\ReportStore;
use App\Sync\SyncLog;
use App\Targets\MacDefaults;
use App\Targets\Symlinks;
use Closure;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Shows times in the Mac's own zone. A TZ naming a zone still wins, the way it does for date(1).
     */
    public function boot(): void
    {
        $zone = getenv('TZ');
        date_default_timezone_set(in_array($zone, timezone_identifiers_list(), true) ? $zone : LocalTimezone::fromLink('/etc/localtime'));
    }

    /**
     * Wires the shell to the login environment, registers the checks, and points the settings,
     * report and backup files at $HOME.
     */
    public function register(): void
    {
        $this->app->singleton(Shell::class, fn () => new ProcessShell(
            fn () => $this->app->make(PathResolver::class)->homeEnvironment()
        ));

        // These two build the environment, so they run with the inherited one.
        $this->app->when([Machine::class, PathResolver::class])->needs(Shell::class)->give(fn () => new ProcessShell);
        $this->app->when([PathResolver::class, DotfilesRepo::class, Symlinks::class, MacDefaults::class])->needs('$inherited')->give(fn () => getenv());
        $this->app->singleton(Machine::class);
        $this->app->singleton(PathResolver::class);
        $this->app->singleton(DotfilesRepo::class);
        $this->app->tag(config('targets'), 'sync-targets');
        $this->app->when(Manifests::class)->needs('$checks')->give(fn () => $this->targetChecks());

        $this->app->singleton(UserSettings::class, fn () => new UserSettings($this->home().'/.config/dotsnitch/config.json'));

        $this->app->singleton(ReportStore::class, fn () => new ReportStore(ReportStore::defaultDirectory(getenv())));
        $this->app->singleton(SyncLog::class, fn () => new SyncLog(ReportStore::defaultDirectory(getenv())));
        $this->app->when(MacDefaults::class)->needs('$baselineFile')->give(fn () => ReportStore::defaultDirectory(getenv()).'/defaults-baseline.json');
        // DOTSNITCH_RELEASES points self-update at another release folder, like it does scripts/install.sh.
        $this->app->when(SelfUpdate::class)->needs('$releases')->give(fn () => getenv('DOTSNITCH_RELEASES') ?: SelfUpdate::RELEASES);
        $this->app->singleton(StatusLock::class, fn () => new StatusLock(ReportStore::defaultDirectory(getenv()).'/'.StatusLock::FILE));
        // launchctl and plutil are system tools, so the login environment isn't needed.
        $this->app->singleton(LaunchAgent::class, fn () => new LaunchAgent(
            new ProcessShell,
            $this->home(),
            ReportStore::defaultDirectory(getenv()).'/schedule.log',
            getenv(),
        ));

        // With the reports and not under ~/.cache, since a file a link replaced has no other copy
        // and cache cleaners empty that folder.
        $this->app->singleton(ManifestEditor::class, fn () => new ManifestEditor(
            ReportStore::defaultDirectory(getenv()).'/backups/'.date('Y-m-d-His')
        ));
    }

    /**
     * A check that reads the whole repo uses every file its patterns match.
     *
     * @return array<string, array{label: string, patterns: list<string>, many: bool, holds: ?Closure(string): bool}> keyed by target key
     */
    private function targetChecks(): array
    {
        $checks = [];

        foreach ($this->app->tagged('sync-targets') as $target) {
            $checks[$target->key()] = [
                'label' => $target->label(),
                'patterns' => $target->manifestPatterns(),
                'many' => $target instanceof ScansWholeRepo,
                'holds' => $target instanceof SharesManifest ? $target->hasEntriesIn(...) : null,
            ];
        }

        return $checks;
    }

    /**
     * @throws RuntimeException when HOME is unset or empty
     */
    private function home(): string
    {
        $home = getenv('HOME');

        if ($home === false || $home === '') {
            throw new RuntimeException('HOME is not set');
        }

        return $home;
    }
}
