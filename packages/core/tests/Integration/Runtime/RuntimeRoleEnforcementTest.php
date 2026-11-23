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
