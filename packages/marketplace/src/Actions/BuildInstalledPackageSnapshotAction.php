<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Data\InstalledPackageData;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildInstalledPackageSnapshotAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<int, InstalledPackageData>
     */
    public function handle(): array
    {
        $extensionRecords = $this->extensionRecords();

        return CapellCore::getInstalledPackages()
            ->values()
            ->map(
                function (PackageData $package) use ($extensionRecords): InstalledPackageData {
                    $extension = $extensionRecords->get($package->name);

                    return new InstalledPackageData(
                        name: $package->name,
                        label: $package->getLabel(),
                        version: $package->version,
                        path: $package->path,
                        paid: $extension instanceof CapellExtension && $extension->is_paid_marketplace_extension,
                        licenceStatus: $extension instanceof CapellExtension ? $extension->marketplace_runtime_status : null,
                        runtimeAllowed: $extension instanceof CapellExtension ? $extension->marketplace_runtime_allowed : null,
                        marketplaceActivation: $this->marketplaceActivation($extension),
                    );
                },
            )
            ->all();
    }

    /**
     * @return Collection<string, CapellExtension>
     */
    private function extensionRecords(): Collection
    {
        try {
            return CapellExtension::query()
                ->get()
                ->keyBy('composer_name');
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function marketplaceActivation(?CapellExtension $extension): array
    {
        $activation = $extension?->marketplace_signed_activation;

        if (! is_array($activation)) {
            return [];
        }

        return array_intersect_key($activation, array_flip([
            'activation_id',
            'activation_nonce',
            'signature_algorithm',
            'signature_issued_at',
            'extension_id',
            'extension_slug',
            'composer_name',
            'package_version',
            'manifest_version',
            'manifest_hash',
            'package_identity',
            'instance_id',
            'verified_site_id',
            'domain',
            'licence_status',
            'effective_license',
            'effective_certification_status',
            'trust_tier',
            'private_docs_entitled',
            'runtime_allowed',
            'issued_at',
            'expires_at',
            'installed_receipt',
            'signature',
        ]));
    }
}
