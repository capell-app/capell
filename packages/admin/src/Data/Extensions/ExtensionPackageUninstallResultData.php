<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionPackageUninstallResultData extends Data
{
    /**
     * @param  list<string>  $uninstalledPackageNames
     */
    public function __construct(
        public readonly bool $successful,
        public readonly array $uninstalledPackageNames,
        public readonly ?string $failedPackageName = null,
        public readonly ?string $failureMessage = null,
    ) {}

    /**
     * @param  list<string>  $uninstalledPackageNames
     */
    public static function success(array $uninstalledPackageNames): self
    {
        return new self(
            successful: true,
            uninstalledPackageNames: $uninstalledPackageNames,
        );
    }

    /**
     * @param  list<string>  $uninstalledPackageNames
     */
    public static function failed(string $packageName, string $failureMessage, array $uninstalledPackageNames): self
    {
        return new self(
            successful: false,
            uninstalledPackageNames: $uninstalledPackageNames,
            failedPackageName: $packageName,
            failureMessage: $failureMessage,
        );
    }
}
