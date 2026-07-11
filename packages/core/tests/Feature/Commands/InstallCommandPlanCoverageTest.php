<?php

declare(strict_types=1);

it('prints a non-interactive fresh demo install plan without mutating the application', function (): void {
    artisanCommand('capell:install', [
        '--plan' => true,
        '--fresh' => 'force',
        '--demo' => true,
        '--package-mode' => 'core',
        '--url' => 'https://example.test',
        '--name' => 'Capell Admin',
        '--email' => 'admin@example.test',
        '--password' => 'password',
        '--no-boost-install' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('You are about to install Capell with a forced fresh database refresh, demo content and a plan-only preview.')
        ->expectsOutputToContain('Capell Install Plan')
        ->expectsOutputToContain('Run preflight checks')
        ->expectsOutputToContain('Mark Capell core installed')
        ->assertSuccessful();
});

it('can explicitly skip all install side effects', function (): void {
    artisanCommand('capell:install', [
        '--no-side-effects' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Skipping all side effects (--no-side-effects).')
        ->assertSuccessful();
});
