<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\LateInstalledCheck;

it('registers each built-in check and remediation once when the Core provider is registered again', function (): void {
    $checksBefore = array_keys(resolve(SecurityWeaknessRegistry::class)->checks());
    $remediationsBefore = array_keys(resolve(SecurityWeaknessRegistry::class)->remediations());

    new CapellServiceProvider(app())->registeringPackage();

    $registry = resolve(SecurityWeaknessRegistry::class);
    expect($checksBefore)->not->toBeEmpty()
        ->and(array_keys($registry->checks()))->toBe($checksBefore)
        ->and(array_keys($registry->remediations()))->toBe($remediationsBefore);
});

it('adopts the checks a package tags while it boots inside the installation window', function (): void {
    $registry = resolve(SecurityWeaknessRegistry::class);
    expect($registry->isFrozen())->toBeTrue()
        ->and($registry->check('late_package.example'))->toBeNull();

    $registrar = resolve(PackageSurfaceRegistrar::class);
    $tagLateCheck = static function (): void {
        app()->tag([LateInstalledCheck::class], SecurityWeaknessCheck::TAG);
    };

    $registrar->duringPackageInstallation($tagLateCheck);
    $adopted = $registry->check('late_package.example');

    // A runtime refresh replays the same boot, which must neither throw nor duplicate.
    $registrar->duringPackageInstallation($tagLateCheck);

    expect($adopted)->toBeInstanceOf(LateInstalledCheck::class)
        ->and($registry->check('late_package.example'))->toBe($adopted)
        ->and(array_count_values(array_map(static fn (SecurityWeaknessCheck $check): string => $check::class, $registry->checks()))[LateInstalledCheck::class])->toBe(1)
        ->and(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new LateInstalledCheck))->toThrow(LogicException::class);
});
