<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Extensions\DeletesExtensionData;
use Capell\Core\Data\PackageData;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package)
 */
final class DeleteExtensionDataAction
{
    use AsObject;

    public function handle(PackageData $package): void
    {
        foreach ($this->dataDeleters($package) as $deleter) {
            $deleter->deleteExtensionData($package);
        }
    }

    /**
     * @return list<DeletesExtensionData>
     */
    private function dataDeleters(PackageData $package): array
    {
        return array_values(collect([
            $package->serviceProviderClass,
            ...$package->getProviderClasses(),
        ])
            ->filter(fn (?string $class): bool => is_string($class) && is_a($class, DeletesExtensionData::class, true))
            ->unique()
            ->map(fn (string $class): DeletesExtensionData => resolve($class))
            ->values()
            ->all());
    }
}
