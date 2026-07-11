<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;

it('passes for a scaffolded theme package', function (): void {
    $packagesDirectory = sys_get_temp_dir() . '/capell-theme-doctor-' . bin2hex(random_bytes(6));
    mkdir($packagesDirectory, 0755, true);

    artisanCommand('capell:make-theme', [
        'theme' => 'equidynamics',
        '--package' => 'app/equidynamics-theme',
        '--path' => $packagesDirectory,
        '--local' => true,
    ])->assertExitCode(Command::SUCCESS);

    artisanCommand('capell:theme:doctor', [
        'theme' => 'equidynamics',
        '--path' => $packagesDirectory . '/equidynamics-theme',
    ])
        ->expectsOutputToContain('All theme checks passed.')
        ->assertExitCode(Command::SUCCESS);
});

it('fails when theme Blade uses root-relative asset urls', function (): void {
    $themeDirectory = sys_get_temp_dir() . '/capell-theme-doctor-fail-' . bin2hex(random_bytes(6));
    mkdir($themeDirectory . '/resources/views', 0755, true);

    file_put_contents($themeDirectory . '/composer.json', json_encode([
        'name' => 'app/broken-theme',
        'autoload' => ['psr-4' => ['App\\BrokenTheme\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($themeDirectory . '/capell.json', json_encode(capellManifestV3Array(
        name: 'app/broken-theme',
        surfaces: ['frontend', 'shared'],
        namespace: 'App\\BrokenTheme',
        providers: ['runtime' => []],
        overrides: [
            'kind' => 'theme',
            'themeKey' => 'broken',
            'extends' => 'default',
            'visibility' => 'support',
            'security' => [
                'riskTier' => 'low',
                'publicSurface' => ['auth' => 'public'],
                'sensitiveData' => [],
                'publicOutput' => [
                    'cacheSafe' => true,
                    'forbidAuthoringSurface' => true,
                    'forbidSecrets' => true,
                    'forbidPublicBladeQueries' => true,
                ],
                'externalHttpClients' => ['clients' => []],
                'adminSurface' => ['authorization' => 'none'],
            ],
        ],
    ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($themeDirectory . '/resources/views/page.blade.php', '<img src="/images/logo.png">');

    artisanCommand('capell:theme:doctor', [
        'theme' => 'broken',
        '--path' => $themeDirectory,
    ])
        ->expectsOutputToContain('Root-relative asset URLs found')
        ->assertExitCode(Command::FAILURE);
});
