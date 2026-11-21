<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * What the user can choose to do about one drift item.
 */
enum Action: string
{
    case Install = 'install';
    case Uninstall = 'uninstall';
    case AddToManifest = 'add';
    case RemoveFromManifest = 'remove';
    case Replace = 'replace';
    case Link = 'link';
    case BackUpAndLink = 'backup-link';
    case Adopt = 'adopt';
    case Repoint = 'repoint';
    case Unlink = 'unlink';
    case ShowDiff = 'diff';
    case Ignore = 'ignore';
    case Skip = 'skip';

    /**
     * The menu wording when the check has nothing more specific. A check can name the replacement
     * or say "Keep" instead.
     */
    public function label(): string
    {
        return match ($this) {
            self::Install => 'Install',
            self::Uninstall => 'Uninstall',
            self::AddToManifest => 'Add to dotfiles',
            self::RemoveFromManifest => 'Remove from dotfiles',
            self::Replace => 'Replace',
            self::Link => 'Create link',
            self::BackUpAndLink => 'Back up and link',
            self::Adopt => 'Adopt into dotfiles',
            self::Repoint => 'Repoint link',
            self::Unlink => 'Remove link',
            self::ShowDiff => 'Show diff',
            self::Ignore => 'Ignore on this Mac',
            self::Skip => 'Skip',
        };
    }
}
