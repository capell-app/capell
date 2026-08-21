<?php

declare(strict_types=1);

use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;
use Capell\Tests\Fixtures\RuntimeRole\FrontendPreviewRuntimeRoleProvider;
use Symfony\Component\Process\Process;

it('selects role-specific provider graphs before Laravel registers providers', function (): void {
    $combined = bootRuntimeRoleFixture('combined');
    $public = bootRuntimeRoleFixture('public');
    $authoring = bootRuntimeRoleFixture('authoring');

    expect($combined['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class)
        ->and($authoring['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class)
        ->and($public['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class)
        ->not->toContain(AuthoringRuntimeRoleProvider::class)
        ->and($public['package_manifest'])->toBe(RuntimeRolePackageManifest::class)
        ->and($combined['services_cache'])->not->toBe($public['services_cache'])
        ->and($public['services_cache'])->not->toBe($authoring['services_cache']);

    foreach ([$combined, $public, $authoring] as $result) {
        foreach (['config_cache', 'packages_cache', 'services_cache', 'routes_cache', 'events_cache'] as $cache) {
            expect($result[$cache])->toContain('/capell-runtime/' . $result['role'] . '/');
        }
    }
});

it('falls back safely to combined while retaining an invalid role diagnostic', function (): void {
    $result = bootRuntimeRoleFixture('not-a-role');

    expect($result['role'])->toBe('combined')
        ->and($result['valid'])->toBeFalse()
        ->and($result['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class);
});

it('re-filters stale generated manifests before public provider registration', function (): void {
    $result = bootRuntimeRoleFixture('public', 'stale');

    expect($result['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class)
        ->not->toContain(AuthoringRuntimeRoleProvider::class);
});

it('preserves and filters ApplicationBuilder providers when the bootstrap manifest is empty', function (): void {
    $combined = bootRuntimeRoleFixture('combined', 'custom');
    $public = bootRuntimeRoleFixture('public', 'custom');

    expect($combined['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class, AuthoringRuntimeRoleProvider::class)
        ->and($public['loaded_providers'])
        ->toContain(FrontendPreviewRuntimeRoleProvider::class)
        ->not->toContain(AuthoringRuntimeRoleProvider::class);
});

/** @return array<string, mixed> */
function bootRuntimeRoleFixture(string $role, ?string $manifestState = null): array
{
    $repositoryPath = dirname(__DIR__, 5);
    $process = new Process([
        PHP_BINARY,
        $repositoryPath . '/tests/fixtures/RuntimeRole/boot-runtime-role.php',
        $role,
        ...($manifestState === null ? [] : [$manifestState]),
    ], $repositoryPath);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    expect($result)->toBeArray();

    return $result;
}
