<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ManifestMatches;
use Closure;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/**
 * Picks the manifest files each check reads. Asks only when the repo leaves it open, and saves
 * the answer under manifests.<check> so the next run doesn't ask again.
 */
final class Manifests
{
    /**
     * @param  array<string, array{label: string, patterns: list<string>, many?: bool, holds?: ?Closure(string): bool}>  $checks  "holds" takes an absolute path, and a file it turns down isn't that check's manifest
     * @param  bool  $unattended  take the defaults instead of asking, and save nothing
     */
    public function __construct(
        private readonly UserSettings $settings,
        private readonly array $checks,
        private readonly bool $unattended = false,
    ) {}

    /**
     * For a run with no terminal: the first base file and no variants, said out loud but not
     * saved, so the first run in a terminal still asks.
     */
    public function withDefaults(): self
    {
        return new self($this->settings, $this->checks, unattended: true);
    }

    /**
     * Narrows to these checks, so nobody is asked about a manifest for a check that won't run.
     *
     * @param  list<string>  $checks  check keys
     */
    public function only(array $checks): self
    {
        return new self($this->settings, array_intersect_key($this->checks, array_flip($checks)), $this->unattended);
    }

    /**
     * @return array<string, list<string>> repo-relative paths per check, base first, then
     *                                     overlays; a check with no manifest maps to []
     */
    public function chosen(string $repo, string $hostname): array
    {
        $patterns = array_map(fn (array $check) => $check['patterns'], $this->checks);
        $chosen = [];

        foreach ((new ManifestLocator($hostname))->locate($repo, $patterns) as $check => $matches) {
            $holds = $this->checks[$check]['holds'] ?? null;

            if ($holds !== null) {
                $held = fn (array $files) => array_values(array_filter($files, fn (string $file) => $holds("{$repo}/{$file}")));
                $matches = new ManifestMatches($held($matches->bases), $held($matches->hostOverlays), $held($matches->variants));
            }

            $chosen[$check] = ($this->checks[$check]['many'] ?? false)
                ? $matches->bases
                : $this->chosenFor($check, $matches);
        }

        return $chosen;
    }

    /**
     * @return string such as "Homebrew", for prompts and tables
     */
    public function label(string $check): string
    {
        return $this->checks[$check]['label'];
    }

    /**
     * @return list<string>
     */
    private function chosenFor(string $check, ManifestMatches $matches): array
    {
        $base = $this->chosenBase($check, $matches->bases);

        return [
            ...($base === null ? [] : [$base]),
            ...$matches->hostOverlays,
            ...$this->chosenVariants($check, $matches->variants),
        ];
    }

    /**
     * @param  list<string>  $bases
     */
    private function chosenBase(string $check, array $bases): ?string
    {
        if (count($bases) <= 1) {
            return $bases[0] ?? null;
        }

        $saved = $this->settings->get("manifests.{$check}.base");

        if (in_array($saved, $bases, true)) {
            return $saved;
        }

        if ($this->unattended) {
            warning("{$this->label($check)} reads {$bases[0]} this run. Run dotsnitch in a terminal to pick from ".implode(', ', $bases).'.');

            return $bases[0];
        }

        $base = select("Which file is the {$this->label($check)} manifest?", $bases);
        $this->settings->set("manifests.{$check}.base", $base);

        return $base;
    }

    /**
     * A saved empty list means the user said no to every variant, so it isn't asked again.
     * Variants added to the repo since then stay out until the user changes the setting.
     *
     * @param  list<string>  $variants
     * @return list<string>
     */
    private function chosenVariants(string $check, array $variants): array
    {
        if ($variants === []) {
            return [];
        }

        $saved = $this->settings->get("manifests.{$check}.variants");

        if (is_array($saved)) {
            return array_values(array_intersect($variants, $saved));
        }

        if ($this->unattended) {
            warning("{$this->label($check)} skips ".implode(', ', $variants).' this run. Run dotsnitch in a terminal to choose.');

            return [];
        }

        $picked = multiselect(
            "Which of these should {$this->label($check)} also read on this Mac?",
            $variants,
            hint: 'Files for other Macs. Pick none to skip them all.',
        );
        $this->settings->set("manifests.{$check}.variants", $picked);

        return $picked;
    }
}
