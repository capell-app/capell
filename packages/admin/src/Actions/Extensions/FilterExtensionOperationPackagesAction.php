<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FilterExtensionOperationPackagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  iterable<ExtensionOperationPackageData>  $packages
     * @return list<ExtensionOperationPackageData>
     */
    public function handle(iterable $packages, ?string $search = null, ?string $tab = null, ?string $productGroup = null): array
    {
        $activeTab = $tab !== null && $tab !== '' ? $tab : null;

        $records = collect($packages)
            ->when(trim((string) $search) !== '', fn (Collection $records): Collection => $records->filter(
                fn (ExtensionOperationPackageData $package): bool => $this->matchesSearch($package, (string) $search),
            ));

        if ($activeTab !== null) {
            $records = $records->filter(
                fn (ExtensionOperationPackageData $package): bool => $this->matchesTab($package, $activeTab),
            );
        }

        return array_values($records
            ->when($productGroup !== null && $productGroup !== '', fn (Collection $records): Collection => $records->filter(
                fn (ExtensionOperationPackageData $package): bool => $package->productGroup === $productGroup,
            ))
            ->values()
            ->all());
    }

    private function matchesSearch(ExtensionOperationPackageData $package, string $search): bool
    {
        $haystack = collect([
            $package->label,
            $package->packageName,
            $package->description,
            $package->version,
            $package->latestVersion,
            $package->tier,
            $package->certification,
            $package->runtimeStatus,
            $package->healthState,
            $package->productGroup,
        ])
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->map(fn (string $value): string => mb_strtolower($value))
            ->implode(' ');

        return collect(str_getcsv(mb_strtolower($search), separator: ' ', escape: '\\'))
            ->filter(fn (?string $term): bool => is_string($term) && trim($term) !== '')
            ->every(fn (?string $term): bool => is_string($term) && str_contains($haystack, trim($term)));
    }

    private function matchesTab(ExtensionOperationPackageData $package, string $tab): bool
    {
        return match ($tab) {
            'needs_attention' => $package->needsAttention,
            'blocked' => $package->blocked,
            'updates' => $package->updateAvailable,
            'premium' => $package->tier === 'premium',
            'installed' => $package->installed,
            'available' => $package->available,
            'core' => $package->core,
            'addons' => ! $package->core,
            default => true,
        };
    }
}
