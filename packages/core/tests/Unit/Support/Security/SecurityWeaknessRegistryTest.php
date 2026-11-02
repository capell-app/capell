<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\Checks\KeyMissingCheck;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;

it('freezes registrations, reopening only during package installation', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);
    $registry->freeze();

    expect(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new KeyMissingCheck))->toThrow(LogicException::class);
    $registry->duringPackageInstallation(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new KeyMissingCheck));
    expect($registry->check('app.key_missing'))->toBeInstanceOf(KeyMissingCheck::class)
        ->and(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new KeyMissingCheck))->toThrow(LogicException::class);
});

it('rejects duplicate weakness registrations', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);

    expect(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new DebugEnabledCheck))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed weakness IDs', function (): void {
    $check = Mockery::mock(SecurityWeaknessCheck::class);
    $check->shouldReceive('weaknessId')->andReturn('Unsafe ID');
    expect(fn (): SecurityWeaknessRegistry => (new SecurityWeaknessRegistry)->registerCheck($check))->toThrow(InvalidArgumentException::class);
});
