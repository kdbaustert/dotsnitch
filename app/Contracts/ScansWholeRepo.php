<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A check that works from the whole dotfiles repo rather than one manifest, such as symlinks. It
 * reads every file its patterns match, and it can run when none match.
 */
interface ScansWholeRepo {}
