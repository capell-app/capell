<?php

declare(strict_types=1);

use Capell\Core\Exceptions\RuntimeRoleMisconfiguredException;
use Symfony\Component\Process\Process;

it('refuses to boot a public node whose host never called the runtime-role bootstrap', function (string $role): void {
    $result = runRuntimeRoleEnforcementFixture($role, 'unbootstrapped');

    expect($result)->toHaveKey('error')
        ->and($result['exception'])->toBe(RuntimeRoleMisconfiguredException::class)
        ->and($result['error'])->toContain('RuntimeRoleBootstrap::configure($app)', $role);
})->with(['public', 'authoring']);

it('keeps the combined role and the Doctor command bootable without the bootstrap call', function (string $role, string $state): void {
    expect(runRuntimeRoleEnforcementFixture($role, $state))->toBe(['registered' => true]);
})->with([
    'combined needs no bootstrap' => ['combined', 'unbootstrapped'],
    'doctor can inspect a misconfigured node' => ['public', 'unbootstrapped-doctor'],
]);

it('refuses a split role whose cache path is pinned by the process environment', function (string $role, string $variable): void {
    $result = runRuntimeRoleEnforcementFixture($role, 'bootstrapped', [$variable => '/shared/cache/pinned.php']);

    expect($result['exception'] ?? null)->toBe(RuntimeRoleMisconfiguredException::class)
        ->and($result['error'])
        ->toContain($variable, '/shared/cache/pinned.php', '/capell-runtime/' . $role . '/', 'Unset ' . $variable);
})->with([
    'public config cache' => ['public', 'APP_CONFIG_CACHE'],
    'public routes cache' => ['public', 'APP_ROUTES_CACHE'],
    'authoring services cache' => ['authoring', 'APP_SERVICES_CACHE'],
    'authoring events cache' => ['authoring', 'APP_EVENTS_CACHE'],
    'public packages cache' => ['public', 'APP_PACKAGES_CACHE'],
]);

it('keeps the documented per-command cache override working for the combined role', function (): void {
    $result = runRuntimeRoleEnforcementFixture('combined', 'bootstrapped', ['APP_CONFIG_CACHE' => '/tmp/no-config.php']);

    // The fixture's ordering provider asserts role paths, so the override surfaces as its own
    // error; the point is that Capell does not refuse it the way it refuses a split role.
    expect($result['exception'] ?? null)->not->toBe(RuntimeRoleMisconfiguredException::class);
});

/**
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function runRuntimeRoleEnforcementFixture(string $role, string $state, array $environment = []): array
{
    $repositoryPath = dirname(__DIR__, 5);
    $process = new Process([
        PHP_BINARY,
        $repositoryPath . '/tests/fixtures/RuntimeRole/boot-runtime-role.php',
        $role,
        $state,
    ], $repositoryPath, [
        'CAPELL_TESTBENCH_RUNTIME_ROLE' => false,
        'APP_CONFIG_CACHE' => false,
        'APP_PACKAGES_CACHE' => false,
        'APP_SERVICES_CACHE' => false,
        'APP_ROUTES_CACHE' => false,
        'APP_EVENTS_CACHE' => false,
        ...$environment,
    ]);
    $process->run();

    $result = json_decode($process->getOutput(), true);

    expect($result)->toBeArray('Fixture output: ' . $process->getOutput() . $process->getErrorOutput());

    return $result;
}
