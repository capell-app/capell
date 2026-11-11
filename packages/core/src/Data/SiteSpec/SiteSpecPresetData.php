<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

/**
 * A ready-to-apply starting point for a site: its pages, navigation and draft
 * copy as a SiteSpec, plus what a person needs to know to choose it.
 */
final class SiteSpecPresetData extends Data
{
    /**
     * @param  list<string>  $businessTypes  Kinds of business this preset suits, for guided setup to match against.
     * @param  list<string>  $suiteKeys  Feature suites the preset works best with.
     * @param  list<string>  $requiredExtensions  Composer package names that must be installed to apply the preset.
     * @param  array<string, mixed>  $spec  A SiteSpec payload; it must pass ValidateSiteSpecAction.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $spec,
        public readonly array $businessTypes = [],
        public readonly array $suiteKeys = [],
        public readonly array $requiredExtensions = [],
    ) {}
}
