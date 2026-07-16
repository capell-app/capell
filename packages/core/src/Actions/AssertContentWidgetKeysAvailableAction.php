<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static void run(array<int, PackageData> $packages)
 */
final class AssertContentWidgetKeysAvailableAction
{
    use AsFake;
    use AsObject;

    /** @param list<PackageData> $packages */
    public function handle(array $packages): void
    {
        $candidateNames = array_map(
            static fn (PackageData $package): string => $package->name,
            $packages,
        );
        $owners = [];

        foreach (CapellCore::getPackages(withoutCore: false) as $installedPackage) {
            if (! $installedPackage instanceof PackageData) {
                continue;
            }

            if (! $installedPackage->isInstalled()) {
                continue;
            }

            if (in_array($installedPackage->name, $candidateNames, true)) {
                continue;
            }

            $this->recordKeys($installedPackage, $owners);
        }

        foreach ($packages as $package) {
            $this->recordKeys($package, $owners);
        }
    }

    /** @param array<string, string> $owners */
    private function recordKeys(PackageData $package, array &$owners): void
    {
        foreach ($package->getContributions() as $contribution) {
            if ($contribution->type !== ExtensionContributionType::ContentWidget) {
                continue;
            }

            $key = $contribution->metadata['key'] ?? null;
            if (! is_string($key)) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            $existingOwner = $owners[$key] ?? null;
            if ($existingOwner !== null && $existingOwner !== $package->name) {
                throw new RuntimeException(sprintf(
                    'Content widget key "%s" is already registered by "%s" and cannot also be registered by "%s".',
                    $key,
                    $existingOwner,
                    $package->name,
                ));
            }

            $owners[$key] = $package->name;
        }
    }
}
