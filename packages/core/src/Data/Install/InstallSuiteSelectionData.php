<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallSuiteSelectionData extends Data
{
    /**
     * @param  list<string>  $packages  Every package the user ended up with, including the suite's always-installed ones.
     * @param  list<InstallSkippedPackageData>  $skipped  Recommended packages left out, each with the reason.
     */
    public function __construct(
        public readonly array $packages,
        public readonly ?string $theme,
        public readonly ?bool $demo,
        public readonly array $skipped = [],
        public readonly ?string $suiteKey = null,
    ) {}
}
