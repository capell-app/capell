<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionUninstallAvailabilityData extends Data
{
    /**
     * @param  list<string>  $dependentPackages
     * @param  list<string>  $dependentPackageNames
     * @param  list<string>  $requiredConfirmationPackageNames
     * @param  list<string>  $uninstallPackageNames
     */
    public function __construct(
        public readonly bool $visible,
        public readonly bool $canRun,
        public readonly array $dependentPackages,
        public readonly array $dependentPackageNames,
        public readonly array $requiredConfirmationPackageNames,
        public readonly array $uninstallPackageNames,
        public readonly ?string $blockReason,
        public readonly string $tooltip,
        public readonly string $modalDescription,
        public readonly bool $showRemovalModeForm,
        public readonly bool $requiresDependentConfirmation,
    ) {}
}
