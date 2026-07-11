<?php

declare(strict_types=1);

use Capell\Core\Testing\ExtensionTestHarness;
use Symfony\Component\Console\Command\Command;

it('creates a project-local theme package scaffold', function (): void {
    $packagesDirectory = sys_get_temp_dir() . '/capell-make-theme-' . bin2hex(random_bytes(6));
    mkdir($packagesDirectory, 0755, true);

    artisanCommand('capell:make-theme', [
        'theme' => 'equidynamics',
        '--package' => 'app/equidynamics-theme',
        '--name' => 'Ben\'s "Launch" Theme',
        '--path' => $packagesDirectory,
        '--local' => true,
    ])
        ->expectsOutputToContain('Created Capell theme: equidynamics')
        ->assertExitCode(Command::SUCCESS);

    $themeDirectory = $packagesDirectory . '/equidynamics-theme';
    $providerPath = $themeDirectory . '/src/EquidynamicsThemeServiceProvider.php';
    $heroViewPath = $themeDirectory . '/resources/views/sections/hero.blade.php';
    $manifest = json_decode((string) file_get_contents($themeDirectory . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode((string) file_get_contents($themeDirectory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lintOutput = [];
    $lintExitCode = 0;

    exec(sprintf('%s -l %s', escapeshellarg(PHP_BINARY), escapeshellarg($providerPath)), $lintOutput, $lintExitCode);

    expect($providerPath)->toBeFile()
        ->and($themeDirectory . '/resources/views/page.blade.php')->toBeFile()
        ->and($heroViewPath)->toBeFile()
        ->and($themeDirectory . '/resources/css/theme.css')->toBeFile()
        ->and($themeDirectory . '/tests/Feature/ThemeContractTest.php')->toBeFile()
        ->and($lintExitCode)->toBe(0)
        ->and($manifest['kind'])->toBe('theme')
        ->and($manifest['themeKey'])->toBe('equidynamics')
        ->and($manifest['displayName'])->toBe('Ben\'s "Launch" Theme')
        ->and($manifest['extends'])->toBe('default')
        ->and($manifest['visibility'])->toBe('support')
        ->and($manifest['providers']['runtime'])->toBe(['App\\EquidynamicsTheme\\EquidynamicsThemeServiceProvider'])
        ->and($composer['extra']['laravel']['providers'])->toBe(['App\\EquidynamicsTheme\\EquidynamicsThemeServiceProvider'])
        ->and(file_get_contents($themeDirectory . '/resources/views/page.blade.php'))->toContain('@frontendAsset')
        ->and(file_get_contents($heroViewPath))->toContain('{{ $body }}')
        ->and(file_get_contents($heroViewPath))->not->toContain('{!! $body !!}');

    ExtensionTestHarness::forPath($themeDirectory)
        ->assertManifestValid()
        ->assertThemeManifest('equidynamics')
        ->assertThemeUsesSafeAssetUrls();
});

it('rejects unsafe parent theme keys', function (): void {
    $packagesDirectory = sys_get_temp_dir() . '/capell-make-theme-bad-extends-' . bin2hex(random_bytes(6));
    mkdir($packagesDirectory, 0755, true);

    artisanCommand('capell:make-theme', [
        'theme' => 'equidynamics',
        '--path' => $packagesDirectory,
        '--extends' => 'Default Theme',
    ])
        ->expectsOutputToContain('The parent theme key must use lowercase letters, numbers, and hyphens.')
        ->assertExitCode(Command::FAILURE);
});

it('rejects unsafe theme keys and target paths', function (array $arguments, string $message): void {
    artisanCommand('capell:make-theme', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);
})->with([
    'bad theme key' => [[
        'theme' => 'EquiDynamics',
        '--path' => sys_get_temp_dir(),
    ], 'lowercase letters'],
    'bad path' => [[
        'theme' => 'equidynamics',
        '--path' => '../outside',
    ], 'Missing or unsafe path'],
]);
