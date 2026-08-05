<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\HealthProbeCommand;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Support\Facades\Artisan;

it('exits zero once it has booted and read the package registry', function (): void {
    $exitCode = Artisan::call('capell:health-probe');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('capell:health-probe ok packages=');
});

it('fills the package registry rather than merely booting', function (): void {
    // Booting alone would pass on an application whose autoload map is broken
    // for the newly installed package, which is exactly the failure this probe
    // exists to catch.
    $registry = resolve(CapellPackageRegistry::class);
    $registry->fill([]);

    Artisan::call('capell:health-probe');

    expect($registry->all())->not->toBeEmpty();
});

it('stays out of the operator-facing command list', function (): void {
    // Machinery, not a tool: it takes no arguments, prints one line, and exists
    // to be exec'd by the install path.
    expect(Artisan::all()['capell:health-probe'] ?? null)
        ->toBeInstanceOf(HealthProbeCommand::class)
        ->and(Artisan::all()['capell:health-probe']->isHidden())->toBeTrue();
});
