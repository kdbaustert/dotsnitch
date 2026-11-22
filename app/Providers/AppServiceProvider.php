<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ScansWholeRepo;
use App\Contracts\Shell;
use App\Support\DotfilesRepo;
use App\Support\LaunchAgent;
use App\Support\LocalTimezone;
use App\Support\Machine;
use App\Support\ManifestEditor;
use App\Support\Manifests;
use App\Support\PathResolver;
use App\Support\ProcessShell;
use App\Support\StatusLock;
use App\Support\UserSettings;
use App\Sync\ReportStore;
use App\Sync\SyncLog;
use App\Targets\Symlinks;
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
        $this->app->when([PathResolver::class, DotfilesRepo::class, Symlinks::class])->needs('$inherited')->give(fn () => getenv());
        $this->app->singleton(Machine::class);
        $this->app->singleton(PathResolver::class);
        $this->app->singleton(DotfilesRepo::class);
        $this->app->tag(config('targets'), 'sync-targets');
        $this->app->when(Manifests::class)->needs('$checks')->give(fn () => $this->targetChecks());

        $this->app->singleton(UserSettings::class, fn () => new UserSettings($this->home().'/.config/dotsnitch/config.json'));

        $this->app->singleton(ReportStore::class, fn () => new ReportStore(ReportStore::defaultDirectory(getenv())));
        $this->app->singleton(SyncLog::class, fn () => new SyncLog(ReportStore::defaultDirectory(getenv())));
        $this->app->singleton(StatusLock::class, fn () => new StatusLock(ReportStore::defaultDirectory(getenv()).'/'.StatusLock::FILE));
        // launchctl and plutil are system tools, so the login environment isn't needed.
        $this->app->singleton(LaunchAgent::class, fn () => new LaunchAgent(
            new ProcessShell,
            $this->home(),
            ReportStore::defaultDirectory(getenv()).'/schedule.log',
            getenv(),
        ));

        $this->app->singleton(ManifestEditor::class, function () {
            $cacheHome = getenv('XDG_CACHE_HOME') ?: $this->home().'/.cache';

            return new ManifestEditor($cacheHome.'/dotsnitch/backups/'.date('Y-m-d-His'));
        });
    }

    /**
     * A check that reads the whole repo uses every file its patterns match.
     *
     * @return array<string, array{label: string, patterns: list<string>, many: bool}> keyed by target key
     */
    private function targetChecks(): array
    {
        $checks = [];

        foreach ($this->app->tagged('sync-targets') as $target) {
            $checks[$target->key()] = [
                'label' => $target->label(),
                'patterns' => $target->manifestPatterns(),
                'many' => $target instanceof ScansWholeRepo,
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
