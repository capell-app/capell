<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Permissions;

use Capell\Core\Contracts\Extensions\DeclaresPermissions;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\Permissions\PermissionSetData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Collects the typed permissions every enabled package declares through a
 * permission contribution that implements DeclaresPermissions.
 *
 * @method static array<string, PermissionSetData> run()
 */
final class ResolveDeclaredPermissionsAction
{
    use AsFake;
    use AsObject;

    /** @return array<string, PermissionSetData> package name => declared permissions */
    public function handle(): array
    {
        $declared = [];

        $packages = CapellCore::getPackages(withoutCore: false)
            ->filter(static fn (PackageData $package): bool => CapellCore::isPackageEnabled($package->name));

        foreach ($packages as $package) {
            try {
                $set = $this->declaredBy($package);
            } catch (Throwable $exception) {
                // One package's broken declaration must not stop the site syncing every other package.
                report($exception);

                continue;
            }

            if ($set->permissions !== []) {
                $declared[$package->name] = $set;
            }
        }

        return $declared;
    }

    private function declaredBy(PackageData $package): PermissionSetData
    {
        $set = new PermissionSetData;

        foreach ($package->getContributions() as $contribution) {
            $declarer = $this->declarer($contribution);

            if ($declarer instanceof DeclaresPermissions) {
                $set = $set->merge($declarer->permissions());
            }
        }

        return $set;
    }

    private function declarer(ExtensionContributionData $contribution): ?DeclaresPermissions
    {
        if ($contribution->type !== ExtensionContributionType::Permission
            || $contribution->class === null
            || ! is_a($contribution->class, DeclaresPermissions::class, true)) {
            return null;
        }

        $declarer = resolve($contribution->class);

        return $declarer instanceof DeclaresPermissions ? $declarer : null;
    }
}
