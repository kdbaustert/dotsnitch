<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

return [
    'name' => 'Dotsnitch',

    'version' => app('git.version'),

    'providers' => [
        AppServiceProvider::class,
    ],
];
