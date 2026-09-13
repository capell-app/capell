<?php

declare(strict_types=1);

use Capell\Tests\Support\RuntimeRoleTestbenchApplication;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Symfony\Component\Process\Process;

it('runs cache-sensitive Testbench Composer commands through the portable runner', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['clear'])
        ->toContain('@php scripts/run-testbench-command.php package:purge-skeleton --ansi');

    foreach (['coverage', 'coverage-report'] as $scriptName) {
        expect($composer['scripts'][$scriptName])
            ->toContain('@php scripts/run-testbench-command.php optimize --except=routes --ansi');
    }
});

it('sets the array cache store in the portable Testbench runner', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/run-testbench-command.php');

    expect($script)
        ->toContain("['CACHE_STORE' => 'array']")
        ->toContain("in_array(\$command, ['list', 'optimize'], true)")
        ->toContain("\$root . '/scripts/configure-testbench-runtime-role.php'")
        ->not->toContain('scripts/screenshots/configure-testbench-runtime-role.php');

    expect($script)
        ->toContain("[PHP_BINARY, \$root . '/vendor/bin/testbench', ...\$arguments]")
        ->not->toContain('CACHE_STORE=array');
});

it('boots the portable Testbench command runner with the runtime role enabled', function (): void {
    $process = new Process([
        PHP_BINARY,
        'scripts/run-testbench-command.php',
        'list',
        '--format=json',
    ], dirname(__DIR__, 2), [
        'CAPELL_TESTBENCH_RUNTIME_ROLE' => 'true',
        'CACHE_STORE' => 'array',
    ]);
    $process->setTimeout(120);
    $process->mustRun();

    $registry = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $commandNames = array_column($registry['commands'] ?? [], 'name');

    expect($commandNames)->toContain('filament:install');
});

it('restores Spatie settings defaults when a cached runtime-role config omits them', function (): void {
    $app = new Application(dirname(__DIR__, 2));
    $config = new Repository(['settings' => null]);
    $app->instance('config', $config);
    $app->instance('config_loaded_from_cache', true);

    $method = new ReflectionMethod(RuntimeRoleTestbenchApplication::class, 'ensureSettingsConfiguration');
    $method->invoke(null, $app);

    expect($config->get('settings.default_repository'))->toBe('database')
        ->and($config->get('settings.cache'))->toBeArray()
        ->and($config->get('settings.repositories'))->toBeArray();
});

it('keeps the screenshot runtime-role helper as an explicit compatibility wrapper', function (): void {
    $wrapper = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/screenshots/configure-testbench-runtime-role.php');

    expect($wrapper)
        ->toContain("require dirname(__DIR__) . '/configure-testbench-runtime-role.php';")
        ->not->toContain('RuntimeRoleBootstrap::configureResolvedApplication');
});
