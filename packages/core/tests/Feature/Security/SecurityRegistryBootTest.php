<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;

it('registers each built-in check and remediation once when the Core provider is registered again', function (): void {
    $checksBefore = array_keys(resolve(SecurityWeaknessRegistry::class)->checks());
    $remediationsBefore = array_keys(resolve(SecurityWeaknessRegistry::class)->remediations());

    new CapellServiceProvider(app())->registeringPackage();

    $registry = resolve(SecurityWeaknessRegistry::class);
    expect($checksBefore)->not->toBeEmpty()
        ->and(array_keys($registry->checks()))->toBe($checksBefore)
        ->and(array_keys($registry->remediations()))->toBe($remediationsBefore);
});
