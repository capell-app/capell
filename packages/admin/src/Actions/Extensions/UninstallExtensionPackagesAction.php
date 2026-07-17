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
 * @method static ExtensionPackageUninstallResultData run(list<string> $packageNames, bool $deletePackage, bool $deleteData)
 */
final class UninstallExtensionPackagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $packageNames
     */
    public function handle(array $packageNames, bool $deletePackage, bool $deleteData): ExtensionPackageUninstallResultData
    {
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
