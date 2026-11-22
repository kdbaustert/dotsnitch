<?php

declare(strict_types=1);

it('refuses to replace a dotsnitch running from source', function () {
    $this->artisan('self-update')
        ->expectsOutputToContain('runs from source or a PHAR')
        ->assertExitCode(1);
});
