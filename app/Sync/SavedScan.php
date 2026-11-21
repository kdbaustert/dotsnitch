<?php

declare(strict_types=1);

namespace App\Sync;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * What one scan found, as ReportStore keeps it: drift item keys per check, and which of them the
 * scan before hadn't seen.
 */
final readonly class SavedScan
{
    /**
     * @param  array<string, list<string>>  $checks  item keys by check: every check that finished, and
     *                                               ones that failed this time with what the scan before had
     * @param  array<string, list<string>>  $new  item keys by check, only checks the scan before also ran
     * @param  ?DateTimeImmutable  $since  when the scan before ran, null on the first one
     */
    public function __construct(
        public DateTimeImmutable $scannedAt,
        public string $repo,
        public array $checks,
        public array $new = [],
        public ?DateTimeImmutable $since = null,
    ) {}

    /**
     * Reads back what toArray() wrote. A report from before "new" was saved has nothing new.
     *
     * @param  array<mixed>  $data
     *
     * @throws RuntimeException when the time or repo is missing, or a time can't be read
     */
    public static function fromArray(array $data): self
    {
        if (! is_string($data['scanned_at'] ?? null) || ! is_string($data['repo'] ?? null)) {
            throw new RuntimeException('The saved report has no scanned_at or repo.');
        }

        return new self(
            self::time($data['scanned_at']),
            $data['repo'],
            self::keysByCheck($data['checks'] ?? []),
            self::keysByCheck($data['new'] ?? []),
            is_string($data['since'] ?? null) ? self::time($data['since']) : null,
        );
    }

    /**
     * @return array{scanned_at: string, repo: string, checks: array<string, list<string>>, new: array<string, list<string>>, since: ?string}
     */
    public function toArray(): array
    {
        return [
            'scanned_at' => $this->scannedAt->format(DateTimeInterface::ATOM),
            'repo' => $this->repo,
            'checks' => $this->checks,
            'new' => $this->new,
            'since' => $this->since?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Every item out of sync, across checks.
     */
    public function count(): int
    {
        return array_sum(array_map(count(...), $this->checks));
    }

    /**
     * Items the scan before didn't have.
     */
    public function newCount(): int
    {
        return array_sum(array_map(count(...), $this->new));
    }

    /**
     * False for every item on a first scan, and for checks the scan before didn't run.
     */
    public function isNew(DriftItem $item): bool
    {
        return in_array($item->key(), $this->new[$item->check] ?? [], true);
    }

    /**
     * @throws RuntimeException in place of DateMalformedStringException, so callers catch one type
     */
    private static function time(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException $e) {
            throw new RuntimeException("The saved report has a time that can't be read: {$value}", previous: $e);
        }
    }

    /**
     * Drops anything that isn't a list of strings, so a hand-edited file can't break a prompt.
     *
     * @return array<string, list<string>>
     */
    public static function keysByCheck(mixed $value): array
    {
        $keys = [];

        foreach (is_array($value) ? $value : [] as $check => $items) {
            if (is_string($check) && is_array($items)) {
                $keys[$check] = array_values(array_filter($items, is_string(...)));
            }
        }

        return $keys;
    }
}
