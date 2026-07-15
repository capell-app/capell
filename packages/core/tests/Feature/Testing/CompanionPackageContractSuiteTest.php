<?php

declare(strict_types=1);

use Capell\Core\Testing\Contracts\CompanionPackageContractSuite;
use Capell\Core\Testing\Data\CompanionPackageContractData;

function validCompanionContract(array $overrides = []): CompanionPackageContractData
{
    $root = dirname(__DIR__, 5);
    $values = [
        'packageRoot' => $root . '/packages/core',
        'manifestPath' => $root . '/packages/core/capell.json',
        'providerClass' => null,
        'migrations' => [],
        'lifecycleAssertion' => fn (): bool => true,
        'authorizationAssertion' => fn (): bool => true,
        'cacheInvalidationAssertion' => fn (): bool => true,
        'publicRender' => fn (): string => '<section>Safe package output</section>',
    ];

    return new CompanionPackageContractData(...array_replace($values, $overrides));
}

it('runs a valid package contract without the curated monorepo', function (): void {
    (new CompanionPackageContractSuite)->run(validCompanionContract());

    expect(true)->toBeTrue();
});

it('reports actionable contract IDs and package paths', function (array $overrides, string $contractId): void {
    $contract = validCompanionContract($overrides);

    expect(fn () => (new CompanionPackageContractSuite)->run($contract))
        ->toThrow(AssertionError::class, "[{$contractId}] {$contract->packageRoot}");
})->with([
    'provider boot' => [['providerClass' => 'Missing\\Provider'], 'provider.boot'],
    'migration discovery' => [['migrations' => ['database/migrations/missing.php']], 'migration.discovery'],
    'install and upgrade' => [['lifecycleAssertion' => fn (): bool => false], 'lifecycle.install-upgrade'],
    'authorization' => [['authorizationAssertion' => fn (): bool => false], 'authorization.protected-resource'],
    'cache invalidation' => [['cacheInvalidationAssertion' => fn (): bool => false], 'cache.invalidation'],
    'public output leakage' => [['publicRender' => fn (): string => '<a href="/admin">Edit</a>'], 'public-output.safety'],
]);

it('reports manifest failures with the owning fixture path', function (): void {
    $contract = validCompanionContract(['manifestPath' => '/missing/package/capell.json']);

    expect(fn () => (new CompanionPackageContractSuite)->run($contract))
        ->toThrow(AssertionError::class, '[manifest.valid] /missing/package/capell.json');
});
