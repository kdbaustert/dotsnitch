<?php

declare(strict_types=1);

namespace App\Sync;

use App\Support\UserSettings;

/**
 * What you've chosen to ignore on this Mac, saved as subject keys under ignore.<check>. The key
 * leaves out the drift kind, so an ignored package stays hidden whether it's missing or untracked.
 */
final class IgnoreList
{
    private const SETTING = 'ignore';

    /**
     * @param  UserSettings  $settings  holds the list in ~/.config/dotsnitch/config.json, so it's per Mac and never committed
     */
    public function __construct(private readonly UserSettings $settings) {}

    /**
     * Saves the item's subject under its check right away.
     */
    public function add(DriftItem $item): void
    {
        $keys = $this->keysFor($item->check);

        if (! in_array($item->subject->key(), $keys, true)) {
            $this->settings->set(self::SETTING.".{$item->check}", [...$keys, $item->subject->key()]);
        }
    }

    /**
     * Stops ignoring these keys. A key that isn't ignored is skipped.
     *
     * @param  list<string>  $keys  subject keys, as all() returns them
     */
    public function remove(string $check, array $keys): void
    {
        $this->settings->set(self::SETTING.".{$check}", array_values(array_diff($this->keysFor($check), $keys)));
    }

    /**
     * @return array<string, list<string>> subject keys per check, leaving out checks with none
     */
    public function all(): array
    {
        $saved = $this->settings->get(self::SETTING, []);
        $checks = is_array($saved) ? array_values(array_filter(array_keys($saved), is_string(...))) : [];

        return array_filter(array_combine($checks, array_map($this->keysFor(...), $checks)));
    }

    /**
     * Takes ignored items out of the report and adds them to its ignored count. Safe to run twice.
     */
    public function apply(DriftReport $report): DriftReport
    {
        $ignored = $this->keysFor($report->check);
        $kept = array_values(array_filter(
            $report->items,
            fn (DriftItem $item) => ! in_array($item->subject->key(), $ignored, true),
        ));

        return new DriftReport($report->check, $kept, $report->ignored + count($report->items) - count($kept));
    }

    /**
     * @return list<string> empty when the check has nothing ignored or the setting was hand-edited into something else
     */
    private function keysFor(string $check): array
    {
        $keys = $this->settings->get(self::SETTING.".{$check}", []);

        return is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [];
    }
}
