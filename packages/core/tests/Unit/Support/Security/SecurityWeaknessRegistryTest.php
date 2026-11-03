<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\Checks\KeyMissingCheck;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;

it('freezes registrations, reopening only during package installation', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);
    $registry->freeze();

    expect(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new KeyMissingCheck))->toThrow(LogicException::class);
    $registry->duringPackageInstallation(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new KeyMissingCheck));
    expect($registry->check('app.key_missing'))->toBeInstanceOf(KeyMissingCheck::class)
        ->and(fn (): SecurityWeaknessRegistry => $registry->registerCheck(new KeyMissingCheck))->toThrow(LogicException::class);
});

it('allows the same owner to replace registrations and rejects a different owner', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $first = new DebugEnabledCheck;
    $replacement = new DebugEnabledCheck;
    $registry->registerCheck($first, 'OwnerA');
    $registry->registerCheck($replacement, 'OwnerA');

    expect($registry->check('app.debug_enabled'))->toBe($replacement)
        ->and(fn (): SecurityWeaknessRegistry => $registry->registerCheck($first, 'OwnerB'))
        ->toThrow(InvalidArgumentException::class, 'OwnerA and cannot be replaced by OwnerB');
});

it('rejects malformed weakness IDs', function (): void {
    $check = Mockery::mock(SecurityWeaknessCheck::class);
    $check->shouldReceive('weaknessId')->andReturn('Unsafe ID');
    expect(fn (): SecurityWeaknessRegistry => (new SecurityWeaknessRegistry)->registerCheck($check))->toThrow(InvalidArgumentException::class);
});

it('rejects weakness IDs longer than the database column', function (): void {
    $check = Mockery::mock(SecurityWeaknessCheck::class);
    $check->shouldReceive('weaknessId')->andReturn(str_repeat('a', 121));
    expect(fn (): SecurityWeaknessRegistry => (new SecurityWeaknessRegistry)->registerCheck($check))->toThrow(InvalidArgumentException::class);
});

it('enforces ownership independently for remediations', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $first = new TestRemediation;
    $replacement = new TestRemediation;
    $registry->registerRemediation($first, 'OwnerA');
    $registry->registerRemediation($replacement, 'OwnerA');

    expect($registry->remediation('app.debug_enabled'))->toBe($replacement)
        ->and(fn (): SecurityWeaknessRegistry => $registry->registerRemediation($first, 'OwnerB'))
        ->toThrow(InvalidArgumentException::class, 'OwnerA and cannot be replaced by OwnerB');
});
