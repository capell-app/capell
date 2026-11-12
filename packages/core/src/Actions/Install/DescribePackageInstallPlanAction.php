<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Lists what InstallPackageAction would run for a package, in the order it runs it,
 * without running any of it.
 */
final class DescribePackageInstallPlanAction
{
    use AsFake;
    use AsObject;

    /**
     * @return list<string>
     */
    public function handle(PackageData $package): array
    {
        $lines = [];

        if ($package->getKind() === 'bundle') {
            $members = array_values(array_filter(
                $package->getRequirements(),
                static fn (string $member): bool => ! CapellCore::getPackage($member)->isInstalled(),
            ));

            if ($members !== []) {
                $lines[] = (string) __('capell-core::extensions.install_plan.bundle_members', ['members' => implode(', ', $members)]);
            }
        }

        if ($package->declaresSchemaMigrations()) {
            $lines[] = (string) __('capell-core::extensions.install_plan.schema_migrations');
        }

        if ($package->declaresSettingsMigrations()) {
            $lines[] = (string) __('capell-core::extensions.install_plan.settings_migrations');
        }

        if (($command = $package->getInstallCommand()) !== null) {
            $lines[] = (string) __('capell-core::extensions.install_plan.install_command', ['command' => $command]);
        }

        if (($action = $package->getInstallAction()) !== null) {
            $lines[] = (string) __('capell-core::extensions.install_plan.install_action', ['action' => $action]);
        }

        return $lines === []
            ? [(string) __('capell-core::extensions.install_plan.nothing_declared')]
            : $lines;
    }
}
