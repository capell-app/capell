<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Capell\Core\Enums\InstallSkipReason;
use Spatie\LaravelData\Data;

/** A recommended package the installer left out, kept so the run can say so and name the next step. */
final class InstallSkippedPackageData extends Data
{
    public function __construct(
        public readonly string $package,
        public readonly InstallSkipReason $reason,
    ) {}
}
