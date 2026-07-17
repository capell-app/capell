<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\UninstallExtensionPackagesAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;

beforeEach(function (): void {
    CapellCore::clearExtensionCache();
});

it('uninstalls registered packages in order and ignores unavailable package names', function (): void {
    CapellCore::registerPackage(name: 'vendor/dependent-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/dependent-extension');
    CapellCore::registerPackage(name: 'vendor/base-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/base-extension');

    $result = UninstallExtensionPackagesAction::run(
        ['vendor/dependent-extension', 'vendor/unavailable-extension', 'vendor/base-extension'],
        deletePackage: false,
        deleteData: false,
    );

    expect($result->successful)->toBeTrue()
        ->and($result->uninstalledPackageNames)->toBe(['vendor/dependent-extension', 'vendor/base-extension'])
        ->and($result->failedPackageName)->toBeNull()
        ->and($result->failureMessage)->toBeNull()
        ->and(CapellCore::isPackageInstalled('vendor/dependent-extension'))->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/base-extension'))->toBeFalse();
});

it('returns the failed package and message after preserving completed uninstalls', function (): void {
    $calls = new stdClass;
    $calls->packages = [];

    app()->bind(UninstallPackageAction::class, fn (): object => new class($calls)
    {
        public function __construct(private stdClass $calls) {}

        public function handle(PackageData $package, bool $delete = false, bool $deleteData = false): void
        {
            $this->calls->packages[] = [$package->name, $delete, $deleteData];

            if ($package->name === 'vendor/failing-extension') {
                throw new RuntimeException('Unable to uninstall failing extension.');
            }
        }
    });

    CapellCore::registerPackage(name: 'vendor/completed-extension', version: '1.0.0');
    CapellCore::registerPackage(name: 'vendor/failing-extension', version: '1.0.0');

    $result = UninstallExtensionPackagesAction::run(
        ['vendor/completed-extension', 'vendor/failing-extension', 'vendor/unreached-extension'],
        deletePackage: true,
        deleteData: true,
    );

    expect($result->successful)->toBeFalse()
        ->and($result->uninstalledPackageNames)->toBe(['vendor/completed-extension'])
        ->and($result->failedPackageName)->toBe('vendor/failing-extension')
        ->and($result->failureMessage)->toBe('Unable to uninstall failing extension.')
        ->and($calls->packages)->toBe([
            ['vendor/completed-extension', true, true],
            ['vendor/failing-extension', true, true],
        ]);
});
