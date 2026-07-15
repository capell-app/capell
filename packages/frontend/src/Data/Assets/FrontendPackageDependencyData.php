<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Enums\FrontendPackageDependencyType;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FrontendPackageDependencyData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $versionConstraint,
        public readonly FrontendPackageDependencyType $type,
        public readonly string $package,
    ) {
        if (preg_match('/^(?:@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid frontend package dependency name [{$name}].");
        }

        if ($versionConstraint === '' || preg_match('/[\s[:cntrl:]]/', $versionConstraint) === 1) {
            throw new InvalidArgumentException("Invalid frontend package dependency constraint for [{$name}].");
        }

        if (preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $package) !== 1) {
            throw new InvalidArgumentException('Frontend package dependency owner must be a Composer package name.');
        }
    }
}
