<?php

declare(strict_types=1);

use App\Targets\Cargo;
use App\Targets\Composer;
use App\Targets\Gh;
use App\Targets\Homebrew;
use App\Targets\Mas;
use App\Targets\Npm;
use App\Targets\Pipx;
use App\Targets\Pnpm;
use App\Targets\Symlinks;
use App\Targets\Uv;
use App\Targets\Vscode;

/*
| Checks the sync command runs, in display order. Each class implements App\Contracts\SyncTarget
| and is tagged "sync-targets" by AppServiceProvider.
*/

return [
    Homebrew::class,
    Mas::class,
    Composer::class,
    Npm::class,
    Pnpm::class,
    Gh::class,
    Pipx::class,
    Uv::class,
    Cargo::class,
    Vscode::class,
    Symlinks::class,
];
