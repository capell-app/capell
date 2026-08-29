<?php

declare(strict_types=1);

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

it('keeps the screenshot runtime-role helper as an explicit compatibility wrapper', function (): void {
    $wrapper = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/screenshots/configure-testbench-runtime-role.php');

    expect($wrapper)
        ->toContain("require dirname(__DIR__) . '/configure-testbench-runtime-role.php';")
        ->not->toContain('RuntimeRoleBootstrap::configureResolvedApplication');
});
