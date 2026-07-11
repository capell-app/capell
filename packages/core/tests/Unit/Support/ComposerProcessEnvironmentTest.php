<?php

declare(strict_types=1);

use Capell\Core\Support\Composer\ComposerProcessEnvironment;

it('forces composer git clones to use public github https urls', function (): void {
    $environment = ComposerProcessEnvironment::forInstall([
        'APP_ENV' => 'testing',
    ]);

    expect($environment)
        ->toMatchArray([
            'APP_ENV' => 'testing',
            'GIT_CONFIG_COUNT' => '3',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => '*',
            'GIT_CONFIG_KEY_1' => 'url.https://github.com/.insteadOf',
            'GIT_CONFIG_VALUE_1' => 'git@github.com:',
            'GIT_CONFIG_KEY_2' => 'url.https://github.com/.insteadOf',
            'GIT_CONFIG_VALUE_2' => 'ssh://git@github.com/',
        ]);
});

it('preserves the active composer file for child composer processes', function (): void {
    $previousComposerFile = getenv('COMPOSER');

    try {
        putenv('COMPOSER=composer.local.json');

        $environment = ComposerProcessEnvironment::forInstall([
            'APP_ENV' => 'testing',
        ]);

        expect($environment['COMPOSER'])->toBe('composer.local.json');
    } finally {
        if (is_string($previousComposerFile)) {
            putenv('COMPOSER=' . $previousComposerFile);
        } else {
            putenv('COMPOSER');
        }
    }
});

it('adds a path for web process composer calls when server environment omits one', function (): void {
    $environment = ComposerProcessEnvironment::forInstall([
        'APP_ENV' => 'testing',
    ]);

    expect($environment['PATH'])->not->toBe('');
});
