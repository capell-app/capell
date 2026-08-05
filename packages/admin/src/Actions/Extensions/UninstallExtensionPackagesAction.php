<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionPackageUninstallResultData;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static ExtensionPackageUninstallResultData run(list<string> $packageNames, bool $deletePackage, bool $deleteData, bool $requiresServerSideTooling = false)
 */
final class UninstallExtensionPackagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $packageNames
     * @param  bool  $requiresServerSideTooling  Whether the Composer removal a
     *                                           `deletePackage` uninstall triggers is an unattended write driven by
     *                                           an HTTP request. The panel passes true; a console caller does not.
     */
    public function handle(
        array $packageNames,
        bool $deletePackage,
        bool $deleteData,
        bool $requiresServerSideTooling = false,
    ): ExtensionPackageUninstallResultData {
        $uninstalledPackageNames = [];

        foreach ($packageNames as $packageName) {
            if (! CapellCore::hasPackage($packageName)) {
                continue;
            }

            try {
                UninstallPackageAction::run(
                    CapellCore::getPackage($packageName),
                    delete: $deletePackage,
                    deleteData: $deleteData,
                    requiresServerSideTooling: $requiresServerSideTooling,
                );
            } catch (Throwable $throwable) {
                return ExtensionPackageUninstallResultData::failed(
                    packageName: $packageName,
                    failureMessage: $throwable->getMessage(),
                    uninstalledPackageNames: $uninstalledPackageNames,
                );
            }

            $uninstalledPackageNames[] = $packageName;
        }

        return ExtensionPackageUninstallResultData::success($uninstalledPackageNames);
    }
}
