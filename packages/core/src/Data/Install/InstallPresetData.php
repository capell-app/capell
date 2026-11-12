<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

/**
 * A named starting point for `capell:install --preset`: the package set, theme and sample-content
 * preference of one install suite, resolved without asking anything.
 */
final class InstallPresetData extends Data
{
    /**
     * @param  list<string>  $packages  Every package the preset installs, including the always-installed foundation.
     * @param  list<InstallSkippedPackageData>  $skipped  Recommended packages left out, each with the reason.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $packages,
        public readonly ?string $theme,
        public readonly ?bool $demo,
        public readonly array $skipped = [],
    ) {}

    public function selection(): InstallSuiteSelectionData
    {
        return new InstallSuiteSelectionData(packages: $this->packages, theme: $this->theme, demo: $this->demo, skipped: $this->skipped);
    }
}
